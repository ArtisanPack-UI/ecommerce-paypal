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
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\CaptureEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalEventMapper;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
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
        'vault token'     => [ 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => 'token-1' ], null ],
    ] );

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
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $issued->id ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 1 )
            ->and( $order->fresh()->total_refunded_amount )->toBe( 0 );
    } );

    it( 'notes a refund PayPal completed that the store recorded as failed', function (): void {
        $order  = webhookPaidOrder();
        $failed = Refund::query()->create( [ 'order_id' => $order->id, 'amount' => 1000, 'currency' => 'USD', 'status' => Refund::STATUS_FAILED ] );
        $this->paypal->respond( 'payments.refunds.get', Orders::body( 'payments/refund', [ 'custom_id' => (string) $failed->id ] ) );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        expect( Refund::query()->count() )->toBe( 1 )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.refund_not_recorded' )->sole()->payload['error'] )->toContain( 'recorded it as failed' );
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
} );

describe( 'queueing', function (): void {
    it( 'queues the side effects on the engine webhook queue', function (): void {
        Queue::fake();
        config( [ 'artisanpack.ecommerce.webhooks.queue' => 'webhooks' ] );

        Webhooks::post( $this, 'PAYMENT.CAPTURE.REFUNDED', Webhooks::refund() )->assertOk();

        Queue::assertPushedOn( 'webhooks', ProcessPayPalWebhook::class, fn ( ProcessPayPalWebhook $job ): bool => 'PAYMENT.CAPTURE.REFUNDED' === $job->event['event_type'] );
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
