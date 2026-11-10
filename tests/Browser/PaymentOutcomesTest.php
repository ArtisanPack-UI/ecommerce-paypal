<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommerceStorefrontLivewire\Support\CheckoutPlacement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Tests\Browser\Support\Checkout;
use Tests\Browser\Support\FakePayPalSdk;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

/*
| What the drivers do with PayPal's answers (focus, live regions, and the
| "Pay now" state), with PayPal's part played by the fake checkout module
| (FakePayPalSdk) and its REST API by PayPalFake. Every step waits on the
| page's real state before asserting.
*/

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => false ] );
    FakePayPalSdk::install();

    $this->customer = Shoppers::signIn();
    $this->cart     = Checkout::readyCart( $this->customer );
} );

/**
 * Opens the checkout's payment step with `$gateway` chosen, after `$setup`
 * ran in the page (before any driver mounted).
 *
 * @param  string  $gateway  Gateway key.
 * @param  string  $setup    JavaScript statement.
 *
 * @return mixed
 */
function outcomePaymentStep( string $gateway, string $setup = '' ): mixed
{
    $page = visit( Checkout::PATH );

    $page->script( '() => { window.paypalFake = { mounted: {} }; ' . $setup . ' }' );

    return $page->click( 'Continue to payment' )
        ->assertSee( 'Payment method' )
        ->radio( 'gateway', $gateway );
}

it( 'says politely, once, that PayPal isn\'t available, without moving focus', function (): void {
    $page = outcomePaymentStep( 'paypal', 'window.paypalFake.eligible = false;' );

    expect( $page->script( FakePayPalSdk::waitFor( "document.querySelector( '[data-payment-driver=\"paypal-buttons\"] [data-paypal-status]' )?.textContent.includes( 'PayPal isn\\'t available for this order.' )" ) ) )->toBeTrue();

    // Give the `payment-failed` round trip time to come back.
    $page->wait( 1 );

    $page->assertScript( <<<'JS'
        () => {
            const root    = document.querySelector( '[data-payment-driver="paypal-buttons"]' );
            const matches = [ ...document.querySelectorAll( '[role="status"], [role="alert"], [aria-live]' ) ]
                .filter( ( box ) => box.textContent.includes( 'PayPal isn\'t available for this order.' ) );

            return 1 === matches.length
                && 'status' === matches[ 0 ].getAttribute( 'role' )
                && '' === root.querySelector( '[data-paypal-error]' ).textContent
                && 'ec-checkout-step-payment' !== document.activeElement?.id;
        }
        JS, true );
} );

it( 'says politely, once, that cards aren\'t available, without moving focus', function (): void {
    $page = outcomePaymentStep( 'paypal-card', 'window.paypalFake.eligible = false;' );

    expect( $page->script( FakePayPalSdk::waitFor( "document.querySelector( '[data-payment-driver=\"paypal-card-fields\"] [data-paypal-status]' )?.textContent.includes( 'Card payments aren\\'t available for this order.' )" ) ) )->toBeTrue();

    $page->wait( 1 );

    $page->assertScript( <<<'JS'
        () => {
            const root    = document.querySelector( '[data-payment-driver="paypal-card-fields"]' );
            const matches = [ ...document.querySelectorAll( '[role="status"], [role="alert"], [aria-live]' ) ]
                .filter( ( box ) => box.textContent.includes( 'Card payments aren\'t available for this order.' ) );

            return 1 === matches.length
                && 'status' === matches[ 0 ].getAttribute( 'role' )
                && '' === root.querySelector( '[data-paypal-error]' ).textContent
                && 'ec-checkout-step-payment' !== document.activeElement?.id;
        }
        JS, true );
} );

it( 'returns focus to the placed order\'s heading when PayPal is closed while finishing a placed order', function (): void {
    $order = Orders::create( [
        'customer_id'    => $this->customer->id,
        'total_amount'   => (int) $this->cart->total_amount,
        'payment_status' => 'pending',
    ] );

    $this->cart->forceFill( [ 'completed_order_id' => $order->id, 'payment_gateway_key' => 'paypal', 'payment_reference' => Orders::ORDER_ID ] )->save();

    // The Order waits for the shopper (it isn't approved yet).
    foreach ( range( 1, 5 ) as $attempt ) {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/created' ) );
    }

    Route::middleware( 'web' )->get( '/test/resume-placement', function () use ( $order ) {
        CheckoutPlacement::remember( $this->cart, $order );

        return redirect( Checkout::PATH );
    } );

    $page = visit( '/test/resume-placement' );

    expect( $page->script( FakePayPalSdk::waitFor( "!! window.paypalFake?.mounted?.buttons && !! document.getElementById( 'ec-checkout-placement-heading' )" ) ) )->toBeTrue();

    $page->script( <<<'JS'
        () => {
            document.activeElement?.blur();
            window.paypalFake.mounted.buttons.callbacks.onCancel( { orderID: window.paypalFake.mounted.buttons.orderId() } );
        }
        JS );

    expect( $page->script( FakePayPalSdk::waitFor( "'ec-checkout-placement-heading' === document.activeElement?.id" ) ) )->toBeTrue();

    expect( Order::query()->count() )->toBe( 1 );
} );

/**
 * Waits for the card form's "Pay now" to be enabled and idle.
 *
 * @param  mixed  $page  Page.
 *
 * @return bool
 */
function payNowReady( mixed $page ): bool
{
    return true === $page->script( FakePayPalSdk::waitFor( <<<'JS'
        ( () => {
            const submit = document.querySelector( '[data-payment-driver="paypal-card-fields"] [data-paypal-card-submit]' );

            return !! submit && ! submit.disabled && 'false' === submit.getAttribute( 'aria-busy' );
        } )()
        JS ) );
}

it( 'gives "Pay now" back when the checkout keeps the shopper on the payment step after a confirmed card', function (): void {
    // PayPal approves the card, but for another amount than the cart's:
    // the driver confirms, and the checkout refuses to place the order.
    foreach ( range( 1, 5 ) as $attempt ) {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE' ) );
    }

    $page = outcomePaymentStep( 'paypal-card' );

    expect( payNowReady( $page ) )->toBeTrue();

    $page->script( "() => document.querySelector( '[data-paypal-card-submit]' ).click()" );

    expect( $page->script( FakePayPalSdk::waitFor( "document.body.textContent.includes( 'Your payment couldn\\'t be confirmed. Try again.' )" ) ) )->toBeTrue()
        ->and( payNowReady( $page ) )->toBeTrue();

    $page->assertScript( "'' === document.querySelector( '[data-payment-driver=\"paypal-card-fields\"] [data-paypal-status]' ).textContent", true );

    expect( Order::query()->count() )->toBe( 0 );
} );

it( 'gives "Pay now" back and says so when the confirm request fails', function (): void {
    $page = outcomePaymentStep( 'paypal-card' );

    expect( payNowReady( $page ) )->toBeTrue();

    // The server errors on the driver's confirm call (a 500, say).
    $page->script( <<<'JS'
        () => {
            const fetch = window.fetch;

            window.fetch = ( input, init ) => String( init?.body ?? '' ).includes( '"confirm"' )
                ? Promise.resolve( new Response( 'Server error', { status: 500 } ) )
                : fetch( input, init );
        }
        JS );

    $page->script( "() => document.querySelector( '[data-paypal-card-submit]' ).click()" );

    expect( payNowReady( $page ) )->toBeTrue()
        ->and( $page->script( FakePayPalSdk::waitFor( "document.querySelector( '[data-payment-driver=\"paypal-card-fields\"] [data-paypal-error]' ).textContent.includes( 'Your card payment couldn\\'t be completed.' )" ) ) )->toBeTrue();

    expect( Order::query()->count() )->toBe( 0 );
} );
