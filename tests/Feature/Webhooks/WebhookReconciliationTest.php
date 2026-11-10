<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Jobs\ProcessPayPalWebhook;
use ArtisanPackUI\EcommercePayPal\Payments\AuthorizationLifecycle;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\CaptureEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalEventMapper;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks as Webhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    Webhooks::boot();
} );

/**
 * The fixture Order, captured for $25.00 with `$source`.
 *
 * @param  string  $source         Payment source.
 * @param  string  $captureStatus  Capture status.
 *
 * @return array<string, mixed>
 */
function webhookCapturedOrder( string $source = 'paypal', string $captureStatus = 'COMPLETED' ): array
{
    return Orders::body( 'orders/captured', [
        'payment_source'                                    => [ $source => 'paypal' === $source ? [ 'email_address' => 'buyer@example.com' ] : [ 'brand' => 'VISA', 'last_digits' => '1111' ] ],
        'purchase_units.0.amount.value'                     => '25.00',
        'purchase_units.0.payments.captures.0.amount.value' => '25.00',
        'purchase_units.0.payments.captures.0.status'       => $captureStatus,
    ] );
}

/**
 * A placed order paid through `$gatewayKey` with the fixture capture.
 *
 * @param  string                $gatewayKey  Gateway key.
 * @param  array<string, mixed>  $paypal      Extra `orders.meta.paypal`.
 *
 * @return Order
 */
function webhookPaidOrder( string $gatewayKey = 'paypal-apple-pay', array $paypal = [] ): Order
{
    return Orders::create(
        [ 'payment_gateway_key' => $gatewayKey, 'payment_status' => 'paid' ],
        array_merge( [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID, 'capture_status' => 'COMPLETED' ], $paypal ),
    );
}

/**
 * Queues the same answer for every call to `$endpoint` in a test.
 *
 * @param  PayPalFake            $fake      Fake.
 * @param  string                $endpoint  Endpoint key.
 * @param  array<string, mixed>  $body      Body.
 *
 * @return void
 */
function respondToWebhookCalls( PayPalFake $fake, string $endpoint, array $body ): void
{
    foreach ( range( 1, 5 ) as $ignored ) {
        $fake->respond( $endpoint, $body );
    }
}

const PAYPAL_SOURCES = [
    'paypal'            => [ 'paypal', 'paypal' ],
    'paypal-card'       => [ 'paypal-card', 'card' ],
    'paypal-apple-pay'  => [ 'paypal-apple-pay', 'apple_pay' ],
    'paypal-google-pay' => [ 'paypal-google-pay', 'google_pay' ],
];

describe( 'payment outcomes', function (): void {
    it( 'places the order when PAYMENT.CAPTURE.COMPLETED arrives for a cart paid with any family key', function ( string $key, string $source ): void {
        $cart = Webhooks::pendingCart( $key );
        respondToWebhookCalls( $this->paypal, 'orders.get', webhookCapturedOrder( $source ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture() )->assertOk();

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_gateway_key )->toBe( $key )
            ->and( $order->payment_reference )->toBe( Orders::ORDER_ID )
            ->and( $cart->refresh()->completed_order_id )->toBe( $order->id )
            ->and( data_get( $order->meta, 'payment.capture_reference' ) )->toBe( Orders::CAPTURE_ID );

        $this->paypal->assertNotSent( 'orders.capture' );
    } )->with( PAYPAL_SOURCES );

    it( 'settles a redelivered event only once', function (): void {
        Webhooks::pendingCart( 'paypal-card' );
        respondToWebhookCalls( $this->paypal, 'orders.get', webhookCapturedOrder( 'card' ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture(), 'WH-DUP' )->assertOk();
        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture(), 'WH-DUP' )->assertOk()->assertJsonPath( 'duplicate', true );

        expect( Order::query()->count() )->toBe( 1 );
    } );

    it( 'reports the related PayPal Order as the session reference', function (): void {
        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.CREATED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'CREATED', 'supplementary_data' => [ 'related_ids' => [ 'order_id' => Orders::ORDER_ID ] ] ] )->assertOk();

        expect( InboundWebhookDelivery::query()->sole()->session_reference )->toBe( Orders::ORDER_ID );
    } );

    it( 'releases a cart when PayPal confirms the capture was denied', function ( string $type ): void {
        $cart   = Webhooks::pendingCart( 'paypal-google-pay' );
        $failed = [];
        addAction( 'ap.ecommerce.checkout.failed', function ( Cart $cart ) use ( &$failed ): void {
            $failed[] = $cart->id;
        } );
        $this->paypal->respond( 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DECLINED' ] ) );

        Webhooks::post( $this, $type, Webhooks::capture( 'DECLINED' ) )->assertOk();

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION )
            ->and( $failed )->toBe( [ $cart->id ] )
            ->and( Order::query()->count() )->toBe( 0 );
    } )->with( [ 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' ] );

    it( 'ignores a denial PayPal does not confirm', function (): void {
        $cart = Webhooks::pendingCart( 'paypal-card' );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ) )->assertOk();

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( InboundWebhookDelivery::query()->sole()->session_reference )->toBe( Orders::ORDER_ID );
    } );

    it( 'takes the session reference from PayPal, not from the body, when releasing a cart', function (): void {
        $cart = Webhooks::pendingCart( 'paypal-card' );
        $this->paypal->respond( 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DENIED', 'supplementary_data.related_ids.order_id' => 'OTHER-ORDER' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ) )->assertOk();

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING )
            ->and( InboundWebhookDelivery::query()->sole()->session_reference )->toBe( 'OTHER-ORDER' );
    } );

    it( 'releases a cart when PayPal confirms its authorization was voided', function (): void {
        $cart = Webhooks::pendingCart( 'paypal' );
        $this->paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization-voided', [ 'supplementary_data.related_ids.order_id' => Orders::ORDER_ID ] ) );

        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.VOIDED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'VOIDED' ] )->assertOk();

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_SELECTION );
    } );

    it( 'fails the delivery so PayPal redelivers when a denial cannot be confirmed', function (): void {
        $cart = Webhooks::pendingCart( 'paypal-card' );
        $this->paypal->error( 'payments.captures.get', 'internal-server-error' );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ) )->assertServerError();

        expect( $cart->refresh()->checkout_state )->toBe( CheckoutState::PAYMENT_PENDING );
    } );

    it( 'acknowledges informational and unhandled events without acting', function ( string $type, array $resource, ?string $reference ): void {
        Queue::fake();

        Webhooks::post( $this, $type, $resource )->assertOk()->assertJsonPath( 'type', $type );

        expect( InboundWebhookDelivery::query()->sole()->session_reference )->toBe( $reference );
        Queue::assertNothingPushed();
    } )->with( [
        'order approved'  => [ 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID, 'status' => 'APPROVED' ], Orders::ORDER_ID ],
        'unhandled event' => [ 'BILLING.SUBSCRIPTION.CREATED', [ 'id' => 'I-123' ], null ],
    ] );

    it( 'queues vault token events for the vault handler, without a session reference', function (): void {
        Queue::fake();

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => 'token-1' ] )->assertOk();

        expect( InboundWebhookDelivery::query()->sole()->session_reference )->toBeNull();
        Queue::assertPushed( ProcessPayPalWebhook::class );
    } );

    it( 'refuses deliveries posted to a sibling key', function (): void {
        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture(), 'WH-1', 'paypal-card' )
            ->assertStatus( 400 )
            ->assertJsonPath( 'code', 'use_paypal_endpoint' );
    } );

    it( 'maps refunds to a refunded outcome with the refund id', function (): void {
        $result = app( PayPalEventMapper::class )->map(
            WebhookResult::verified( 'PAYMENT.CAPTURE.REFUNDED', 'WH-R', [ 'resource' => Webhooks::refund() ] ),
        );

        // Refund resources carry no Order id: the outcome stands without one.
        expect( $result->outcome )->toBe( WebhookResult::OUTCOME_REFUNDED )
            ->and( $result->refundReference )->toBe( '1JU08902781691411' )
            ->and( $result->sessionReference )->toBeNull()
            ->and( $result->hasPaymentOutcome() )->toBeFalse();
    } );
} );

describe( 'refunds made in PayPal', function (): void {
    it( 'records an Apple Pay dashboard refund on the order', function (): void {
        $order = webhookPaidOrder( 'paypal-apple-pay' );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null, 'invoice_id' => null ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        $refund = Refund::query()->sole();

        expect( $refund->order_id )->toBe( $order->id )
            ->and( $refund->amount )->toBe( 1000 )
            ->and( $refund->gateway_reference )->toBe( '1JU08902781691411' )
            ->and( $refund->reason )->toBe( 'Defective product' )
            ->and( $order->fresh()->payment_status )->toBe( 'partially_refunded' )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 1000 );
    } );

    it( 'records the amount PayPal reports, not the one in the body', function (): void {
        $order = webhookPaidOrder();
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null, 'amount.value' => '4.00' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->sole()->amount )->toBe( 400 )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 400 );
    } );

    it( 'does not duplicate a refund the store issued (matched by custom_id)', function (): void {
        $order  = webhookPaidOrder();
        $issued = Refund::query()->create( [ 'order_id' => $order->id, 'amount' => 1000, 'currency' => 'USD', 'status' => Refund::STATUS_PENDING ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $issued->id, 'invoice_id' => 'refund-' . $issued->id ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 1 )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 0 );
    } );

    it( 'does not take a custom_id as the store\'s refund id unless the invoice_id matches it', function (): void {
        $order  = webhookPaidOrder();
        $issued = Refund::query()->create( [ 'order_id' => $order->id, 'amount' => 1000, 'currency' => 'USD', 'status' => Refund::STATUS_PENDING ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $issued->id, 'invoice_id' => 'INV-ELSEWHERE' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 2 )
            ->and( Refund::query()->where( 'gateway_reference', '1JU08902781691411' )->sole()->id )->not->toBe( $issued->id )
            ->and( $issued->fresh()->status )->toBe( Refund::STATUS_PENDING )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 1000 );
    } );

    it( 'settles a refund the store recorded as failed when PayPal completed it', function (): void {
        $order  = webhookPaidOrder();
        $failed = Refund::query()->create( [ 'order_id' => $order->id, 'amount' => 1000, 'currency' => 'USD', 'status' => Refund::STATUS_FAILED ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $failed->id, 'invoice_id' => 'refund-' . $failed->id ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        $refund = $failed->fresh();

        expect( Refund::query()->count() )->toBe( 1 )
            ->and( $refund->status )->toBe( Refund::STATUS_SUCCEEDED )
            ->and( $refund->gateway_reference )->toBe( '1JU08902781691411' )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 1000 )
            ->and( $order->fresh()->payment_status )->toBe( 'partially_refunded' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_settled' )->sole()->payload )->toMatchArray( [ 'refund_id' => '1JU08902781691411', 'store_refund_id' => $failed->id ] )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_not_recorded' )->exists() )->toBeFalse();
    } );

    it( 'notes a failed store refund PayPal completed for a different amount', function (): void {
        $order  = webhookPaidOrder();
        $failed = Refund::query()->create( [ 'order_id' => $order->id, 'amount' => 1500, 'currency' => 'USD', 'status' => Refund::STATUS_FAILED ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $failed->id, 'invoice_id' => 'refund-' . $failed->id ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( $failed->fresh()->status )->toBe( Refund::STATUS_FAILED )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 0 )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_not_recorded' )->exists() )->toBeTrue()
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_settled' )->exists() )->toBeFalse();
    } );

    it( 'records a refund once when PayPal sends it under two event ids', function (): void {
        $order = webhookPaidOrder();
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null ] ) );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund(), 'WH-A' )->assertOk();
        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund(), 'WH-B' )->assertOk();

        expect( Refund::query()->count() )->toBe( 1 )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 1000 );
    } );

    it( 'records a reversal with a timeline entry', function (): void {
        $order = webhookPaidOrder( 'paypal-card' );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null, 'note_to_payer' => 'Chargeback' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REVERSED', Webhooks::refund() )->assertOk();

        $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_reversed' )->sole();

        expect( $entry->payload )->toMatchArray( [ 'capture_id' => Orders::CAPTURE_ID, 'refund_id' => '1JU08902781691411', 'amount' => '10.00', 'reason' => 'Chargeback' ] )
            ->and( Refund::query()->sole()->gateway_reference )->toBe( '1JU08902781691411' );
    } );

    it( 'skips refunds that have not completed', function (): void {
        webhookPaidOrder();
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null, 'status' => 'PENDING' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 0 );
    } );

    it( 'notes a refund the engine refuses to record', function (): void {
        $order = webhookPaidOrder( 'paypal', [] );
        $order->forceFill( [ 'total_refunded_amount' => 9500 ] )->save();
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 0 )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_not_recorded' )->exists() )->toBeTrue();
    } );

    it( 'finds the order through the capture PayPal names when the capture was never recorded', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal-google-pay', 'payment_status' => 'paid' ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->sole()->order_id )->toBe( $order->id );
        $this->paypal->assertSent( 'payments.captures.get' );
    } );

    it( 'ignores refunds for captures no order recorded', function (): void {
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => null ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 0 );
    } );
} );

describe( 'pending captures', function (): void {
    it( 'writes a timeline entry, and nothing else, for PAYMENT.CAPTURE.PENDING', function (): void {
        $order = webhookPaidOrder( 'paypal', [ 'capture_status' => 'PENDING' ] );
        $this->paypal->respond( 'payments.captures.get', 'payments/capture-pending' );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.PENDING', Webhooks::capture( 'PENDING' ) )->assertOk();

        $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->sole();

        expect( $entry->event_type )->toBe( 'paypal.capture_pending' )
            ->and( $entry->payload['capture_id'] )->toBe( Orders::CAPTURE_ID )
            ->and( OrderNote::query()->count() )->toBe( 0 )
            ->and( $order->fresh()->payment_status )->toBe( 'paid' );
    } );

    it( 'settles a pending capture when it completes', function (): void {
        $order = webhookPaidOrder( 'paypal', [ 'capture_status' => 'PENDING', 'pending_reason' => 'ECHECK' ] );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture() )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'COMPLETED' )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'pending_reason' ) )->toBeNull()
            ->and( OrderTimelineEntry::query()->where( 'event_type', 'paypal.capture_completed' )->count() )->toBe( 1 );
    } );

    it( 'leaves a capture that was never pending alone', function (): void {
        $order = webhookPaidOrder();

        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture() )->assertOk();

        expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    } );

    it( 'warns staff when a pending capture on a placed order is denied', function (): void {
        $order  = webhookPaidOrder( 'paypal-card', [ 'capture_status' => 'PENDING' ] );
        $denied = [];
        addAction( CaptureEventHandler::DENIED_HOOK, function ( Order $order, string $captureId, string $status ) use ( &$denied ): void {
            $denied[] = [ $order->id, $captureId, $status ];
        } );
        // Once for the outcome, once for the order's side effects.
        respondToWebhookCalls( $this->paypal, 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DENIED' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ) )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'DENIED' )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->sole()->body )->toContain( Orders::CAPTURE_ID )
            ->and( OrderTimelineEntry::query()->where( 'event_type', 'paypal.capture_denied' )->count() )->toBe( 1 )
            ->and( $denied )->toBe( [ [ $order->id, Orders::CAPTURE_ID, 'DENIED' ] ] );
    } );

    it( 'fails the order\'s payment, and leaves its status alone, when a pending capture is denied', function (): void {
        $order  = webhookPaidOrder( 'paypal', [ 'capture_status' => 'PENDING' ] );
        $status = $order->status;
        $failed = [];
        addAction( 'ap.ecommerce.payment.failed', function ( mixed $gateway, Throwable $reason, Order $order ) use ( &$failed ): void {
            $failed[] = [ $order->id, $reason->getMessage() ];
        } );
        respondToWebhookCalls( $this->paypal, 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DENIED' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ), 'WH-DENIED-1' )->assertOk();
        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ), 'WH-DENIED-2' )->assertOk();

        $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'payment.capture_failed' )->sole();

        expect( $order->fresh()->payment_status )->toBe( 'failed' )
            ->and( $order->fresh()->status )->toBe( $status )
            ->and( $entry->payload )->toMatchArray( [ 'reference' => Orders::CAPTURE_ID, 'code' => 'capture_denied', 'message' => 'PayPal denied the pending payment.', 'source' => 'provider' ] )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_denied' )->count() )->toBe( 1 )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->count() )->toBe( 1 )
            ->and( $failed )->toBe( [ [ $order->id, 'PayPal denied the pending payment.' ] ] );
    } );

    it( 'reports a captureDenied listener that throws, and records the denial once', function (): void {
        Exceptions::fake();
        $order = webhookPaidOrder( 'paypal', [ 'capture_status' => 'PENDING' ] );
        $calls = 0;
        addAction( CaptureEventHandler::DENIED_HOOK, function () use ( &$calls ): void {
            $calls++;

            throw new RuntimeException( 'listener failed' );
        } );
        respondToWebhookCalls( $this->paypal, 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DENIED' ] ) );
        $event = [ 'id' => 'WH-DENIED', 'event_type' => 'PAYMENT.CAPTURE.DENIED', 'resource' => [ 'id' => Orders::CAPTURE_ID ] ];

        ( new ProcessPayPalWebhook( $event ) )->handle( app( PayPalWebhookProcessor::class ) );
        ( new ProcessPayPalWebhook( $event ) )->handle( app( PayPalWebhookProcessor::class ) );

        expect( $calls )->toBe( 1 )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->count() )->toBe( 1 )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_denied' )->count() )->toBe( 1 )
            ->and( $order->fresh()->payment_status )->toBe( 'failed' );

        Exceptions::assertReported( fn ( RuntimeException $exception ): bool => 'listener failed' === $exception->getMessage() );
    } );

    it( 'keeps a completed capture completed when PAYMENT.CAPTURE.PENDING is processed after it', function (): void {
        $order = webhookPaidOrder();
        $this->paypal->respond( 'payments.captures.get', 'payments/capture-pending' );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.PENDING', Webhooks::capture( 'PENDING' ) )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'COMPLETED' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_pending' )->exists() )->toBeFalse();
    } );

    it( 'records a completion that arrives before the store recorded the capture', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [ 'order_id' => Orders::ORDER_ID ] );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.COMPLETED', Webhooks::capture() )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_id' ) )->toBe( Orders::CAPTURE_ID )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'COMPLETED' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_completed' )->count() )->toBe( 1 );
    } );

    it( 'records a denial that arrives before the store recorded the capture', function (): void {
        $order  = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [ 'order_id' => Orders::ORDER_ID ] );
        $denied = [];
        addAction( CaptureEventHandler::DENIED_HOOK, function ( Order $order, string $captureId, string $status ) use ( &$denied ): void {
            $denied[] = [ $order->id, $captureId, $status ];
        } );
        respondToWebhookCalls( $this->paypal, 'payments.captures.get', Orders::body( 'payments/capture', [ 'status' => 'DENIED' ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.DENIED', Webhooks::capture( 'DENIED' ) )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_id' ) )->toBe( Orders::CAPTURE_ID )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'DENIED' )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->sole()->body )->toContain( Orders::CAPTURE_ID )
            ->and( $denied )->toBe( [ [ $order->id, Orders::CAPTURE_ID, 'DENIED' ] ] )
            ->and( $order->fresh()->payment_status )->toBe( 'failed' );
    } );
} );

describe( 'voided authorizations', function (): void {
    it( 'records an authorization PayPal voided on a placed order, so the lifecycle skips it', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [
            'order_id'      => Orders::ORDER_ID,
            'intent'        => 'authorize',
            'authorization' => [
                'id'            => Orders::AUTHORIZATION_ID,
                'status'        => 'CREATED',
                'created_at'    => '2026-11-09T12:05:00+00:00',
                'authorized_at' => '2026-11-09T12:05:00+00:00',
                'expires_at'    => '2026-12-08T12:05:00+00:00',
            ],
        ] );
        respondToWebhookCalls( $this->paypal, 'payments.authorizations.get', Orders::body( 'payments/authorization-voided' ) );

        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.VOIDED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'VOIDED' ], 'WH-VOID-1' )->assertOk();
        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.VOIDED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'VOIDED' ], 'WH-VOID-2' )->assertOk();

        $fresh = $order->fresh();

        expect( PayPalOrderMeta::get( $fresh, 'authorization.status' ) )->toBe( 'VOIDED' )
            ->and( PayPalOrderMeta::get( $fresh, 'authorization.expires_at' ) )->toBe( '2026-12-08T12:05:00+00:00' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.authorization_voided' )->sole()->payload )->toBe( [ 'authorization_id' => Orders::AUTHORIZATION_ID ] )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->sole()->body )->toContain( Orders::AUTHORIZATION_ID )
            ->and( $fresh->payment_status )->toBe( 'paid' )
            ->and( app( AuthorizationLifecycle::class )->process( $fresh, CarbonImmutable::parse( '2026-11-13T12:00:00Z' ) ) )->toBe( [] );
    } );

    it( 'ignores a voided authorization that is not the order\'s', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [
            'order_id'      => Orders::ORDER_ID,
            'authorization' => [ 'id' => 'OTHER-AUTHORIZATION', 'status' => 'CREATED' ],
        ] );
        $this->paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization-voided' ) );
        $this->paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization-voided' ) );

        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.VOIDED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'VOIDED' ] )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'authorization.status' ) )->toBe( 'CREATED' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    } );

    it( 'ignores an authorization PayPal does not confirm was voided', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [
            'order_id'      => Orders::ORDER_ID,
            'authorization' => [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'CREATED' ],
        ] );

        Webhooks::post( $this, 'PAYMENT.AUTHORIZATION.VOIDED', [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'VOIDED' ] )->assertOk();

        expect( PayPalOrderMeta::get( $order->fresh(), 'authorization.status' ) )->toBe( 'CREATED' )
            ->and( OrderNote::query()->where( 'order_id', $order->id )->count() )->toBe( 0 );
    } );
} );

describe( 'queueing', function (): void {
    it( 'queues the side effects on the engine webhook queue', function (): void {
        Queue::fake();
        config( [ 'artisanpack.ecommerce.webhooks.queue' => 'webhooks' ] );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        Queue::assertPushedOn( 'webhooks', ProcessPayPalWebhook::class, fn ( ProcessPayPalWebhook $job ): bool => 'PAYMENT.CAPTURE.REFUNDED' === $job->event['event_type'] );
    } );

    it( 'retries for 24 hours, backing off to an hour', function (): void {
        $job = new ProcessPayPalWebhook( [ 'id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [ 'id' => 'R-1' ] ] );

        expect( CarbonImmutable::instance( $job->retryUntil() )->diffInMinutes( now()->addHours( 24 ) ) )->toBeLessThan( 1 )
            ->and( $job->backoff )->toBe( [ 30, 120, 600, 1800, 3600 ] );
    } );

    it( 'logs a job that gave up with the event and PayPal debug ids', function (): void {
        config( [
            'artisanpack.ecommerce-paypal.logging.channel' => 'paypal-webhook-job-test',
            'logging.channels.paypal-webhook-job-test'     => [ 'driver' => 'monolog', 'handler' => TestHandler::class ],
        ] );
        $job = new ProcessPayPalWebhook( [ 'id' => 'WH-GAVE-UP', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [ 'id' => 'R-1' ] ] );

        $job->failed( new PayPalApiException( 'PayPal is down.', 503, 'INTERNAL_SERVER_ERROR', debugId: 'debug-123' ) );

        $records = Log::channel( 'paypal-webhook-job-test' )->getLogger()->getHandlers()[0]->getRecords();

        expect( $records )->toHaveCount( 1 )
            ->and( $records[0]->level->getName() )->toBe( 'ERROR' )
            ->and( $records[0]->context )->toMatchArray( [
                'event_id'    => 'WH-GAVE-UP',
                'event_type'  => 'PAYMENT.CAPTURE.REFUNDED',
                'resource_id' => 'R-1',
                'debug_id'    => 'debug-123',
            ] );
    } );

    it( 'retries when PayPal cannot be reached', function (): void {
        webhookPaidOrder();
        $this->paypal->error( 'payments.refunds.get', 'internal-server-error' );

        expect( fn () => ( new ProcessPayPalWebhook( [ 'id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => Webhooks::refund() ] ) )->handle( app( PayPalWebhookProcessor::class ) ) )
            ->toThrow( PayPalApiException::class );

        expect( Refund::query()->count() )->toBe( 0 );
    } );
} );

describe( 'hardening', function (): void {
    it( 'queues only the event id, type, and resource id', function (): void {
        Queue::fake();

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() + [ 'payer' => [ 'email_address' => 'buyer@example.com' ] ], 'WH-MIN' )->assertOk();

        Queue::assertPushed( ProcessPayPalWebhook::class, fn ( ProcessPayPalWebhook $job ): bool => [
            'id'         => 'WH-MIN',
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource'   => [ 'id' => '1JU08902781691411' ],
        ] === $job->event );
    } );

    it( 'does not repeat an order\'s side effects when the same event is processed again', function (): void {
        $order = webhookPaidOrder( 'paypal', [ 'capture_status' => 'PENDING' ] );
        respondToWebhookCalls( $this->paypal, 'payments.captures.get', Orders::body( 'payments/capture-pending' ) );
        $event = [ 'id' => 'WH-AGAIN', 'event_type' => 'PAYMENT.CAPTURE.PENDING', 'resource' => [ 'id' => Orders::CAPTURE_ID ] ];

        app( PayPalWebhookProcessor::class )->process( $event );
        app( PayPalWebhookProcessor::class )->process( $event );

        expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.capture_pending' )->count() )->toBe( 1 )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'webhook_events' ) )->toBe( [ 'WH-AGAIN' ] );
    } );

    it( 'keeps only the latest processed event ids', function (): void {
        $order = webhookPaidOrder();

        foreach ( range( 1, 55 ) as $index ) {
            PayPalOrderMeta::rememberEvent( $order, 'WH-' . $index );
        }

        $seen = PayPalOrderMeta::get( $order->fresh(), 'webhook_events' );

        expect( $seen )->toHaveCount( 50 )
            ->and( $seen[0] )->toBe( 'WH-6' )
            ->and( PayPalOrderMeta::rememberEvent( $order->fresh(), 'WH-55' ) )->toBeFalse();
    } );
} );
