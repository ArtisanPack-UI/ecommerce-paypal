<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Support\Sleep;
use Tests\Browser\Support\Accessibility;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

/*
| The account "Saved payment methods" page (spec §10.3, §13) in a real
| browser: axe, keyboard-only use, and focus after a delete. PayPal's
| vault API is answered by PayPalFake, so no credentials are needed; the
| sandbox journey that saves a real card is in Sandbox/CheckoutJourneysTest.
*/

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );

    $this->customer = Shoppers::signIn();
} );

/**
 * Opens the page.
 *
 * @return mixed
 */
function visitSavedMethods(): mixed
{
    return visit( route( EcommercePayPalServiceProvider::ACCOUNT_ROUTE ) );
}

it( 'has no accessibility violations with saved methods, an expired card, and a default', function (): void {
    PayPalPaymentToken::factory()->paypal()->default()->create( [ 'customer_id' => $this->customer->id, 'display' => [ 'email' => 'ada@example.test' ] ] );
    PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id, 'display' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'expiry' => '2020-01' ] ] );

    $page = visitSavedMethods()
        ->assertSee( 'PayPal (ada@example.test)' )
        ->assertSee( 'Mastercard ending in 4444' )
        // Status is said in words, not only by the badge colour.
        ->assertSee( 'Default' )
        ->assertSee( 'Expired 01/2020' );

    Accessibility::assertAccessible( $page );
} );

it( 'has no accessibility violations when nothing is saved', function (): void {
    $page = visitSavedMethods()->assertSee( 'No saved payment methods' );

    Accessibility::assertAccessible( $page );
} );

it( 'removes a method with the keyboard alone, then puts focus on the list heading', function (): void {
    PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $this->customer->id, 'paypal_token_id' => 'tok-keep', 'display' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2031-01' ] ] );
    $card = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id, 'paypal_token_id' => 'tok-del', 'display' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'expiry' => '2031-01' ] ] );

    $page = visitSavedMethods();

    $page->script( "() => document.querySelector( '[data-paypal-saved-method-delete=\"{$card->id}\"]' ).focus()" );
    $page->keys( '[data-paypal-saved-method-delete="' . $card->id . '"]', 'Enter' )
        ->assertSee( 'Remove this payment method?' )
        ->assertSee( 'Mastercard ending in 4444 will be removed from PayPal and from your account.' );

    Accessibility::assertAccessible( $page );

    $page->script( "() => document.querySelector( '[data-paypal-saved-method-delete-confirm]' ).focus()" );
    $page->keys( '[data-paypal-saved-method-delete-confirm]', 'Enter' )
        ->assertSee( 'Payment method removed' )
        ->assertDontSee( 'Mastercard ending in 4444' )
        ->assertSee( 'Visa ending in 1111' );

    $page->wait( 0.3 )
        ->assertScript( "document.activeElement?.hasAttribute( 'data-paypal-saved-methods-heading' )", true );

    $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 1 );

    expect( $card->refresh()->trashed() )->toBeTrue();
} );

it( 'makes a method the default with the keyboard', function (): void {
    PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $this->customer->id, 'display' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2031-01' ] ] );
    $card = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id, 'display' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'expiry' => '2031-01' ] ] );

    $page = visitSavedMethods();

    $page->script( "() => document.querySelector( '[data-paypal-saved-method-make-default=\"{$card->id}\"]' ).focus()" );
    $page->keys( '[data-paypal-saved-method-make-default="' . $card->id . '"]', 'Enter' )
        ->assertSee( 'Default payment method updated' );

    expect( $card->refresh()->is_default )->toBeTrue();
} );
