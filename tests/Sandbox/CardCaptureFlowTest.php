<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\InboundWebhookDelivery;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use Carbon\CarbonImmutable;
use Tests\Sandbox\Support\PayPalSandbox;

/*
| Spec §12.3, intent `capture`: a card-source Order paid with a sandbox
| test card, captured through the engine's checkout, its events fetched
| from PayPal and reconciled, then refunded. The engine's order is checked
| after every step.
*/

beforeEach( function (): void {
    if ( ! PayPalSandbox::configured() ) {
        $this->markTestSkipped( PayPalSandbox::skipReason() );
    }

    PayPalSandbox::boot( [ 'intent' => 'capture' ] );
    PayPalSandbox::payWithTestCard();
} );

it( 'captures, reconciles, and refunds a card payment in the PayPal sandbox', function (): void {
    $startedAt = CarbonImmutable::now();
    $checkout  = app( CheckoutService::class );
    $cart      = PayPalSandbox::cart( 'paypal-card' );

    // 1. The Order is created at PayPal for the cart's total.
    $session = $checkout->createPaymentSession( $cart, [ 'idempotency_key' => 'sandbox-suite-' . $cart->id . '-' . $startedAt->getTimestamp() ] );

    expect( $session->gatewayKey )->toBe( 'paypal-card' )
        ->and( (int) $session->amount->getAmount() )->toBe( 2_500 )
        ->and( $session->amount->getCurrency()->getCode() )->toBe( 'USD' )
        ->and( $cart->refresh()->payment_reference )->toBe( $session->reference );

    $paypalOrder = PayPalSandbox::client()->orders()->get( $session->reference );

    expect( $paypalOrder->intent() )->toBe( 'CAPTURE' )
        ->and( $paypalOrder->status() )->toBeIn( [ 'APPROVED', 'COMPLETED' ] );

    // 2. Finalize places the order and captures the payment.
    $result = $checkout->finalize( $cart, $session->reference );
    $order  = $result->order->refresh();

    expect( $result->isComplete() )->toBeTrue()
        ->and( $order->payment_status )->toBe( 'paid' )
        ->and( $order->payment_gateway_key )->toBe( 'paypal-card' )
        ->and( $order->payment_reference )->toBe( $session->reference )
        ->and( (int) $order->total_amount )->toBe( 2_500 );

    $captureId = (string) PayPalOrderMeta::get( $order, 'capture_id' );

    expect( $captureId )->not->toBe( '' )
        ->and( PayPalOrderMeta::get( $order, 'capture_status' ) )->toBe( 'COMPLETED' )
        ->and( PayPalSandbox::client()->payments()->getCapture( $captureId )->status() )->toBe( 'COMPLETED' );

    // A second finalize returns the same order without moving money again.
    expect( $checkout->finalize( $cart->refresh(), $session->reference )->order->id )->toBe( $order->id )
        ->and( PayPalSandbox::client()->orders()->get( $session->reference )->raw['purchase_units'][0]['payments']['captures'] ?? [] )->toHaveCount( 1 );

    // 3. PayPal's capture event runs through the mapper and the engine
    //    reconciler, which matches the placed order and changes nothing.
    $completed = PayPalSandbox::awaitEvent( 'PAYMENT.CAPTURE.COMPLETED', $captureId, $startedAt );

    PayPalSandbox::deliver( $this, $completed )->assertOk();

    expect( InboundWebhookDelivery::query()->where( 'event_id', $completed['id'] )->sole()->session_reference )->toBe( $session->reference )
        ->and( Order::query()->count() )->toBe( 1 )
        ->and( $order->refresh()->payment_status )->toBe( 'paid' );

    // 4. A partial refund through the engine.
    $refund = app( RefundService::class )->issue( $order, [
        [ 'order_item_id' => $order->items()->firstOrFail()->id, 'quantity' => 0, 'amount' => 1_000 ],
    ], null, 'Sandbox suite refund' );

    expect( $refund->status )->toBeIn( [ Refund::STATUS_SUCCEEDED, Refund::STATUS_PENDING ] )
        ->and( (string) $refund->gateway_reference )->not->toBe( '' )
        ->and( $order->refresh()->payment_status )->toBe( 'partially_refunded' )
        ->and( (int) $order->total_refunded_amount )->toBe( 1_000 );

    // 5. PayPal's refund event reconciles to the refund already recorded.
    $refunded = PayPalSandbox::awaitEvent( 'PAYMENT.CAPTURE.REFUNDED', (string) $refund->gateway_reference, $startedAt );

    PayPalSandbox::deliver( $this, $refunded )->assertOk();

    expect( Refund::query()->where( 'order_id', $order->id )->count() )->toBe( 1 )
        ->and( $refund->refresh()->status )->toBe( Refund::STATUS_SUCCEEDED )
        ->and( (int) $order->refresh()->total_refunded_amount )->toBe( 1_000 )
        ->and( $order->payment_status )->toBe( 'partially_refunded' );
} );
