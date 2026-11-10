<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use Tests\Browser\Support\Accessibility;
use Tests\Browser\Support\Checkout;
use Tests\Browser\Support\PayPalSandboxPages as PayPal;
use Tests\Sandbox\Support\PayPalSandbox;
use Tests\Support\Shoppers;

/*
| Shopper journeys against the PayPal sandbox (spec §12.4): the buttons
| from the real SDK, cancelling and approving in PayPal, card fields with
| validation and 3-D Secure, and a saved card from checkout to the account
| page. Runs nightly in CI (.github/workflows/sandbox.yml); skipped without
| sandbox credentials. See tests/Browser/Support/PayPalSandboxPages.php for
| the environment each journey needs.
*/

pest()->group( 'sandbox' );

beforeEach( function (): void {
    if ( ! PayPalSandbox::configured() ) {
        $this->markTestSkipped( PayPalSandbox::skipReason() );
    }

    PayPalSandbox::boot( [ 'vault.enabled' => true ] );

    $this->customer = Shoppers::signIn();

    Checkout::readyCart( $this->customer );
} );

/**
 * The payment step with `$gateway` chosen and its PayPal Order created.
 *
 * @param  string  $gateway  Gateway key.
 *
 * @return mixed
 */
function sandboxPaymentStep( string $gateway ): mixed
{
    return visit( Checkout::PATH )
        ->click( 'Continue to payment' )
        ->radio( 'gateway', $gateway );
}

/**
 * The PayPal Order the shopper's open cart is paying with.
 *
 * @return string
 */
function sandboxOrderId(): string
{
    return (string) Cart::query()->where( 'customer_id', test()->customer->id )->whereNull( 'completed_order_id' )->latest( 'id' )->value( 'payment_reference' );
}

/**
 * Waits for the card fields to mount.
 *
 * @param  mixed  $page  The page.
 *
 * @return mixed
 */
function sandboxCardFields( mixed $page ): mixed
{
    return $page->assertPresent( '[data-paypal-card-field="number"] iframe' )
        ->assertButtonEnabled( '[data-paypal-card-submit]' );
}

describe( 'PayPal buttons', function (): void {
    it( 'renders the buttons from the sandbox SDK', function (): void {
        $page = sandboxPaymentStep( 'paypal' )
            ->assertPresent( '[data-paypal-buttons] iframe' )
            ->assertSeeNothingIn( '[data-payment-driver="paypal-buttons"] [role="alert"]' );

        Accessibility::assertAccessible( $page );
    } );

    it( 'returns to the payment step with a message when the shopper cancels in PayPal', function (): void {
        $page = sandboxPaymentStep( 'paypal' )->assertPresent( '[data-paypal-buttons] iframe' );

        PayPal::cancel( $page, sandboxOrderId() )
            ->assertPathIs( Checkout::PATH )
            ->assertSee( 'Your payment wasn\'t completed.' )
            ->assertSee( 'Payment method' );

        expect( Order::query()->count() )->toBe( 0 );
    } );

    it( 'completes an order when the sandbox buyer approves in PayPal', function (): void {
        PayPal::require( [ 'PAYPAL_SANDBOX_BUYER_EMAIL', 'PAYPAL_SANDBOX_BUYER_PASSWORD' ] );

        $page    = sandboxPaymentStep( 'paypal' )->assertPresent( '[data-paypal-buttons] iframe' );
        $orderId = sandboxOrderId();

        PayPal::approve( $page, $orderId )->assertSee( 'Thank you for your order' );

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_gateway_key )->toBe( 'paypal' )
            ->and( $order->payment_reference )->toBe( $orderId );
    } );
} );

describe( 'card fields', function (): void {
    it( 'shows labelled inline errors for an incomplete card', function (): void {
        $page = sandboxCardFields( sandboxPaymentStep( 'paypal-card' ) );

        $page->click( '[data-paypal-card-submit]' )
            ->assertSee( 'Check the highlighted card details.' )
            ->assertSee( 'Enter a valid card number.' );

        Accessibility::assertAccessible( $page );

        expect( Order::query()->count() )->toBe( 0 );
    } );

    it( 'pays with a card that passes 3-D Secure without a challenge', function (): void {
        PayPal::require( [ 'PAYPAL_SANDBOX_3DS_FRICTIONLESS_CARD' ] );

        $page = sandboxCardFields( sandboxPaymentStep( 'paypal-card' ) );

        PayPal::fillCard( $page, (string) PayPal::env( 'PAYPAL_SANDBOX_3DS_FRICTIONLESS_CARD' ) )
            ->click( '[data-paypal-card-submit]' )
            ->assertSee( 'Thank you for your order' );

        $order = Order::query()->sole();

        expect( $order->payment_status )->toBe( 'paid' )
            ->and( $order->payment_gateway_key )->toBe( 'paypal-card' );
    } );

    it( 'pays after the bank\'s 3-D Secure challenge', function (): void {
        PayPal::require( [ 'PAYPAL_SANDBOX_3DS_CHALLENGE_CARD', 'PAYPAL_SANDBOX_3DS_CODE' ] );

        $page = sandboxCardFields( sandboxPaymentStep( 'paypal-card' ) );

        PayPal::fillCard( $page, (string) PayPal::env( 'PAYPAL_SANDBOX_3DS_CHALLENGE_CARD' ) )
            ->click( '[data-paypal-card-submit]' );

        PayPal::completeChallenge( $page )->assertSee( 'Thank you for your order' );

        expect( Order::query()->sole()->payment_status )->toBe( 'paid' );
    } );

    it( 'refuses a card whose bank doesn\'t shift liability, and hands focus back', function (): void {
        PayPal::require( [ 'PAYPAL_SANDBOX_3DS_FAILED_CARD' ] );

        $page = sandboxCardFields( sandboxPaymentStep( 'paypal-card' ) );

        PayPal::fillCard( $page, (string) PayPal::env( 'PAYPAL_SANDBOX_3DS_FAILED_CARD' ) )
            ->click( '[data-paypal-card-submit]' );

        if ( null !== PayPal::env( 'PAYPAL_SANDBOX_3DS_CODE' ) ) {
            // Some failing cards still show the challenge first.
            $page->script( '() => new Promise( ( resolve ) => setTimeout( resolve, 3000 ) )' );
        }

        $page->assertSeeIn( '[data-payment-driver="paypal-card-fields"] [role="alert"]', PayPalGateway::liabilityShiftMessage() )
            ->assertScript( "document.activeElement?.hasAttribute( 'data-paypal-card-submit' )", true );

        expect( Order::query()->where( 'payment_status', 'paid' )->count() )->toBe( 0 );
    } );
} );

it( 'saves a card at checkout, pays with it next time, and removes it from the account page', function (): void {
    // 1. Pay with a new card and save it.
    $page = sandboxCardFields( sandboxPaymentStep( 'paypal-card' ) );

    $page->check( 'Save this card for faster checkout' );

    sandboxCardFields( $page );

    PayPal::fillCard( $page, (string) ( PayPal::env( 'PAYPAL_SANDBOX_CARD_NUMBER' ) ?? PayPalSandbox::DEFAULT_CARD ) )
        ->click( '[data-paypal-card-submit]' )
        ->assertSee( 'Thank you for your order' );

    $token = PayPalPaymentToken::query()->where( 'customer_id', $this->customer->id )->sole();

    expect( $token->type )->toBe( 'card' );

    // 2. The next checkout offers it, and pays with it without card fields.
    Checkout::readyCart( $this->customer );

    sandboxPaymentStep( 'paypal-card' )
        ->assertPresent( '[data-paypal-saved-cards]' )
        ->click( '[data-paypal-saved-card-pay]' )
        ->assertSee( 'Thank you for your order' );

    $second = Order::query()->latest( 'id' )->firstOrFail();

    expect( Order::query()->where( 'payment_status', 'paid' )->count() )->toBe( 2 )
        ->and( PayPalOrderMeta::get( $second, 'capture_id' ) )->not->toBeNull();

    // 3. The account page removes it, at PayPal too.
    $account = visit( route( EcommercePayPalServiceProvider::ACCOUNT_ROUTE ) )
        ->click( '[data-paypal-saved-method-delete="' . $token->id . '"]' )
        ->click( '[data-paypal-saved-method-delete-confirm]' )
        ->assertSee( 'Payment method removed' );

    Accessibility::assertAccessible( $account );

    expect( $token->refresh()->trashed() )->toBeTrue()
        ->and( fn () => PayPalSandbox::client()->vault()->getPaymentToken( $token->paypal_token_id ) )->toThrow( PayPalApiException::class );
} );
