<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Support\Sleep;
use Tests\Browser\Support\Accessibility;
use Tests\Browser\Support\Checkout;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

/*
| The four drivers' own markup at the storefront's payment step (spec §9,
| §13), without PayPal credentials: Orders come from PayPalFake, and the
| SDK, asked for with a client id PayPal doesn't know, fails to load. That
| is the state checked here: every control labelled, the failure announced
| in an alert, and no axe violations. The sandbox journeys, which load the
| real SDK, are in Sandbox/CheckoutJourneysTest.
*/

beforeEach( function (): void {
    Sleep::fake();

    PayPalFake::fake();
    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );

    Checkout::readyCart( Shoppers::signIn() );
} );

/**
 * The payment step with `$gateway` chosen.
 *
 * @param  string  $gateway  Gateway key.
 *
 * @return mixed
 */
function paymentStepWith( string $gateway ): mixed
{
    return visit( Checkout::PATH )
        ->click( 'Continue to payment' )
        ->assertSee( 'Payment method' )
        ->radio( 'gateway', $gateway );
}

it( 'labels the PayPal buttons and announces a failed load', function (): void {
    $page = paymentStepWith( 'paypal' )
        ->assertPresent( '[data-payment-driver="paypal-buttons"] [role="group"][aria-label="Pay with PayPal"]' )
        ->assertSee( 'You\'ll finish paying in a PayPal window.' )
        ->assertSee( 'Save PayPal for faster checkout' )
        ->assertSeeIn( '[data-payment-driver="paypal-buttons"] [role="alert"]', 'PayPal couldn\'t load. Check your connection and reload the page.' );

    Accessibility::assertAccessible( $page );
} );

it( 'labels every card field and announces a failed load', function (): void {
    $page = paymentStepWith( 'paypal-card' )
        ->assertSeeIn( '[data-payment-driver="paypal-card-fields"]', 'Name on card' )
        ->assertSeeIn( '[data-payment-driver="paypal-card-fields"]', 'Card number' )
        ->assertSeeIn( '[data-payment-driver="paypal-card-fields"]', 'Expiration date' )
        ->assertSeeIn( '[data-payment-driver="paypal-card-fields"]', 'Security code' )
        ->assertSee( 'Save this card for faster checkout' )
        ->assertSeeIn( '[data-payment-driver="paypal-card-fields"] [role="alert"]', 'The card form couldn\'t load. Check your connection and reload the page.' );

    // Each field's group is named by its visible label and described by
    // its inline error.
    $page->assertScript( <<<'JS'
        () => [ ...document.querySelectorAll( '[data-paypal-card-field]' ) ].every( ( group ) =>
            document.getElementById( group.getAttribute( 'aria-labelledby' ) )?.textContent.trim() !== ''
            && null !== document.getElementById( group.getAttribute( 'aria-describedby' ) )
        )
        JS, true );

    Accessibility::assertAccessible( $page );
} );

it( 'labels the wallet buttons and tells the shopper when one can\'t be used', function ( string $gateway, string $driver, string $wallet ): void {
    $page = paymentStepWith( $gateway )
        ->assertPresent( '[data-payment-driver="' . $driver . '"] [role="group"][aria-label="Pay with ' . $wallet . '"]' )
        ->wait( 1 );

    // Chromium has no Apple Pay, and the SDK can't load here: either way
    // the shopper is told in words, without a colour-only state.
    $page->assertScript( <<<JS
        () => {
            const root = document.querySelector( '[data-payment-driver="{$driver}"]' );

            return [ ...root.querySelectorAll( '[role="status"], [role="alert"]' ) ].some( ( box ) => box.textContent.includes( '{$wallet}' ) );
        }
        JS, true );

    Accessibility::assertAccessible( $page );
} )->with( [
    'Apple Pay'  => [ 'paypal-apple-pay', 'paypal-apple-pay', 'Apple Pay' ],
    'Google Pay' => [ 'paypal-google-pay', 'paypal-google-pay', 'Google Pay' ],
] );
