<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\FraudProvider;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Registries\FraudProviderRegistry;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\Ecommerce\ValueObjects\FraudDecision;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentFinalization;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use Carbon\CarbonImmutable;
use Tests\Sandbox\Support\PayPalSandbox;

/*
| Spec §12.3, intent `authorize`: the same card flow, captured through the
| authorization, and a second Order whose authorization the engine voids
| when its fraud check blocks the order.
*/

beforeEach( function (): void {
    if ( ! PayPalSandbox::configured() ) {
        $this->markTestSkipped( PayPalSandbox::skipReason() );
    }

    PayPalSandbox::boot( [ 'intent' => 'authorize' ] );
    PayPalSandbox::payWithTestCard();
} );

it( 'authorizes, captures, reconciles, and refunds a card payment in the PayPal sandbox', function (): void {
    $startedAt = CarbonImmutable::now();
    $checkout  = app( CheckoutService::class );
    $cart      = PayPalSandbox::cart( 'paypal-card' );

    $session = $checkout->createPaymentSession( $cart, [ 'idempotency_key' => 'sandbox-suite-auth-' . $cart->id . '-' . $startedAt->getTimestamp() ] );

    expect( PayPalSandbox::client()->orders()->get( $session->reference )->intent() )->toBe( 'AUTHORIZE' );

    $result = $checkout->finalize( $cart, $session->reference );
    $order  = $result->order->refresh();

    $authorizationId = (string) PayPalOrderMeta::get( $order, 'authorization.id' );
    $captureId       = (string) PayPalOrderMeta::get( $order, 'capture_id' );

    expect( $result->isComplete() )->toBeTrue()
        ->and( $order->payment_status )->toBe( 'paid' )
        ->and( $authorizationId )->not->toBe( '' )
        ->and( $captureId )->not->toBe( '' )
        ->and( PayPalSandbox::client()->payments()->getAuthorization( $authorizationId )->status() )->toBe( 'CAPTURED' );

    foreach ( [ 'PAYMENT.AUTHORIZATION.CREATED' => $authorizationId, 'PAYMENT.CAPTURE.COMPLETED' => $captureId ] as $type => $resourceId ) {
        $event = PayPalSandbox::awaitEvent( $type, $resourceId, $startedAt );

        PayPalSandbox::deliver( $this, $event )->assertOk();

        expect( InboundWebhookDelivery::query()->where( 'event_id', $event['id'] )->sole()->session_reference )->toBe( $session->reference )
            ->and( $order->refresh()->payment_status )->toBe( 'paid' );
    }

    $refund = app( RefundService::class )->issue( $order, [
        [ 'order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 0, 'amount' => 2_500 ],
    ], null, 'Sandbox suite refund' );

    expect( (string) $refund->gateway_reference )->not->toBe( '' )
        ->and( $order->refresh()->payment_status )->toBe( 'refunded' );

    PayPalSandbox::deliver( $this, PayPalSandbox::awaitEvent( 'PAYMENT.CAPTURE.REFUNDED', (string) $refund->gateway_reference, $startedAt ) )->assertOk();

    expect( $order->refunds()->count() )->toBe( 1 )
        ->and( $order->refresh()->payment_status )->toBe( 'refunded' );
} );

it( 'voids the authorization when the engine blocks the order', function (): void {
    $startedAt = CarbonImmutable::now();

    app( FraudProviderRegistry::class )->register( 'sandbox-suite-block', new class implements FraudProvider {
        public function key(): string
        {
            return 'sandbox-suite-block';
        }

        public function label(): string
        {
            return 'Sandbox suite: block everything';
        }

        public function assess( Cart $cart, Address $shipping, PaymentSession $session ): FraudDecision
        {
            return FraudDecision::block( 100, [ 'sandbox_suite' ] );
        }
    } );
    config( [ 'artisanpack.ecommerce.fraud.provider' => 'sandbox-suite-block' ] );

    $checkout = app( CheckoutService::class );
    $cart     = PayPalSandbox::cart( 'paypal-card' );
    $session  = $checkout->createPaymentSession( $cart, [ 'idempotency_key' => 'sandbox-suite-void-' . $cart->id . '-' . $startedAt->getTimestamp() ] );

    // A card Order created with its card may come back approved rather
    // than authorized. Authorize it the way finalize would (same
    // PayPal-Request-Id), so there is an authorization to void.
    $paypalOrder = PayPalSandbox::client()->orders()->get( $session->reference );

    if ( null === $paypalOrder->latestAuthorization() ) {
        $paypalOrder = PayPalSandbox::client()->orders()->authorize( $session->reference, 'authorize-' . $session->reference );
    }

    $authorizationId = $paypalOrder->latestAuthorization()?->id();

    expect( $authorizationId )->not->toBeNull()
        ->and( PayPalSandbox::client()->payments()->getAuthorization( (string) $authorizationId )->status() )->toBe( 'CREATED' );

    $result = $checkout->finalize( $cart, $session->reference );
    $order  = $result->order->refresh();

    expect( $result->payment?->status )->toBe( PaymentFinalization::STATUS_BLOCKED )
        ->and( $order->payment_status )->toBe( 'failed' )
        ->and( PayPalSandbox::client()->payments()->getAuthorization( (string) $authorizationId )->status() )->toBe( 'VOIDED' )
        ->and( PayPalOrderMeta::get( $order, 'authorization.status' ) )->toBe( 'VOIDED' )
        ->and( PayPalOrderMeta::get( $order, 'capture_id' ) )->toBeNull()
        ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'payment.fraud_blocked' )->sole()->payload['released'] ?? null )->toBe( 'voided' );

    $voided = PayPalSandbox::awaitEvent( 'PAYMENT.AUTHORIZATION.VOIDED', (string) $authorizationId, $startedAt );

    PayPalSandbox::deliver( $this, $voided )->assertOk();

    expect( InboundWebhookDelivery::query()->where( 'event_id', $voided['id'] )->sole()->session_reference )->toBe( $session->reference )
        ->and( $order->refresh()->payment_status )->toBe( 'failed' );

    // Voiding again is a no-op.
    app( PaymentGatewayRegistry::class )->get( 'paypal-card' )->voidPendingPayment( $order );

    expect( PayPalOrderMeta::get( $order->refresh(), 'authorization.status' ) )->toBe( 'VOIDED' );
} );
