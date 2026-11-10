<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalSavedMethodException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

/*
| Saved payment methods for API storefronts (#42): the gateways' payment
| options (engine AcceptsPaymentOptions, ArtisanPack-UI/ecommerce#211),
| sent as `payment_options` on `POST checkout/{cart}/session`.
*/

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );
} );

/**
 * The gateway registered under `$key`.
 *
 * @param  string  $key  Gateway key.
 *
 * @return PayPalGateway
 */
function optionsGateway( string $key ): PayPalGateway
{
    return app( PaymentGatewayRegistry::class )->get( $key );
}

/**
 * A signed-in API shopper (Sanctum) and their cart, ready for its payment
 * session through `$gateway`.
 *
 * @param  string  $gateway  Gateway key.
 *
 * @return array{0: Customer, 1: Cart}
 */
function apiShopperCart( string $gateway = 'paypal-card' ): array
{
    $customer = Customer::factory()->create( [ 'user_id' => 7 ] );

    test()->actingAs( new GenericUser( [ 'id' => 7 ] ), 'sanctum' );

    return [ $customer, readyForSession( Shoppers::cart( $customer, $gateway ) ) ];
}

/**
 * `$cart` without the fixture session the shopper helpers attach, so the
 * engine creates one.
 *
 * @param  Cart  $cart  Cart.
 *
 * @return Cart
 */
function readyForSession( Cart $cart ): Cart
{
    $cart->forceFill( [ 'payment_reference' => null, 'checkout_state' => CheckoutState::PAYMENT_SELECTION ] )->save();

    return $cart->refresh();
}

/**
 * Answers every Order create and read with an Order for the shopper
 * helpers' $25.00 cart.
 *
 * @param  PayPalFake  $fake  Fake.
 * @param  int         $times Answers to queue.
 *
 * @return void
 */
function answerOrders( PayPalFake $fake, int $times = 4 ): void
{
    $order = Orders::body( 'orders/created', [ 'purchase_units.0.amount.value' => '25.00', 'purchase_units.0.amount.breakdown' => null ] );

    foreach ( range( 1, $times ) as $ignored ) {
        $fake->respond( 'orders.create', $order );
        $fake->respond( 'orders.get', $order );
    }
}

/**
 * Creates the payment session over REST.
 *
 * @param  Cart                       $cart     Cart.
 * @param  array<string, mixed>|null  $options  `payment_options`, or null to leave it out.
 *
 * @return TestResponse
 */
function sessionWithOptions( Cart $cart, ?array $options ): TestResponse
{
    $body = null === $options ? [] : [ 'payment_options' => $options ];

    return test()->postJson( "/api/ecommerce/v1/checkout/{$cart->token}/session", $body, [ 'Idempotency-Key' => (string) Str::uuid() ] );
}

/**
 * The bodies of every Order create sent to PayPal.
 *
 * @param  PayPalFake  $fake  Fake.
 *
 * @return array<int, array<string, mixed>>
 */
function createdOrders( PayPalFake $fake ): array
{
    return array_map( static fn ( Request $request ): array => $request->data(), $fake->sent( 'orders.create' ) );
}

describe( 'option rules', function (): void {
    it( 'offers saving on the vaulting gateways and saved cards on the card gateway, to a signed-in shopper', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-card' );

        expect( optionsGateway( 'paypal' )->paymentOptionRules( $cart ) )->toBe( [ PayPalGateway::SAVE_OPTION => [ 'sometimes', 'boolean' ] ] )
            ->and( optionsGateway( 'paypal-card' )->paymentOptionRules( $cart ) )->toBe( [
                PayPalGateway::SAVE_OPTION         => [ 'sometimes', 'boolean' ],
                PayPalGateway::SAVED_METHOD_OPTION => [ 'sometimes', 'integer', 'min:1' ],
            ] )
            ->and( optionsGateway( 'paypal-apple-pay' )->paymentOptionRules( $cart ) )->toBe( [] )
            ->and( optionsGateway( 'paypal-google-pay' )->paymentOptionRules( $cart ) )->toBe( [] );
    } );

    it( 'offers nothing to a guest, or while vaulting is off', function ( Closure $setUp ): void {
        $cart = $setUp();

        expect( optionsGateway( 'paypal' )->paymentOptionRules( $cart ) )->toBe( [] )
            ->and( optionsGateway( 'paypal-card' )->paymentOptionRules( $cart ) )->toBe( [] );
    } )->with( [
        'guest'       => [ fn (): Cart => PayPalWebhooks::pendingCart( 'paypal-card' ) ],
        'vault off'   => [ function (): Cart {
            $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-card' );

            config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

            return $cart;
        } ],
        'signed out'  => [ function (): Cart {
            $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-card' );

            Auth::forgetGuards();

            return $cart;
        } ],
    ] );
} );

describe( 'REST session', function (): void {
    it( 'asks PayPal to vault the card when the storefront sends save_payment_method', function (): void {
        [ , $cart ] = apiShopperCart( 'paypal-card' );
        answerOrders( $this->paypal );

        sessionWithOptions( $cart, [ PayPalGateway::SAVE_OPTION => true ] )->assertOk();

        expect( createdOrders( $this->paypal )[0]['payment_source']['card']['attributes']['vault'] ?? null )->toBe( [ 'store_in_vault' => 'ON_SUCCESS' ] )
            ->and( app( PayPalVault::class )->wantsToSave( $cart->refresh() ) )->toBeTrue();
    } );

    it( 'asks PayPal to vault a PayPal wallet', function (): void {
        [ , $cart ] = apiShopperCart( 'paypal' );
        answerOrders( $this->paypal );

        sessionWithOptions( $cart, [ PayPalGateway::SAVE_OPTION => true ] )->assertOk();

        expect( createdOrders( $this->paypal )[0]['payment_source']['paypal']['attributes']['vault'] ?? null )
            ->toBe( [ 'store_in_vault' => 'ON_SUCCESS', 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER' ] );
    } );

    it( 'pays with the shopper\'s saved card, with no card fields', function (): void {
        [ $customer, $cart ] = apiShopperCart( 'paypal-card' );
        $card                = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-saved' ] );
        answerOrders( $this->paypal );

        sessionWithOptions( $cart, [ PayPalGateway::SAVED_METHOD_OPTION => $card->id ] )->assertOk();

        $source = createdOrders( $this->paypal )[0]['payment_source']['card'];

        expect( $source['vault_id'] )->toBe( 'tok-saved' )
            ->and( $source )->not->toHaveKey( 'attributes.vault' )
            ->and( $cart->refresh()->meta['paypal'][ PayPalVault::SAVED_TOKEN_META ]['id'] ?? null )->toBe( $card->id );
    } );

    it( 'refuses another shopper\'s saved card without creating an Order', function (): void {
        [ , $cart ] = apiShopperCart( 'paypal-card' );
        $foreign    = PayPalPaymentToken::factory()->create( [ 'customer_id' => Customer::factory()->create()->id ] );

        sessionWithOptions( $cart, [ PayPalGateway::SAVED_METHOD_OPTION => $foreign->id ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.0.code', PayPalSavedMethodException::UNAVAILABLE );

        $this->paypal->assertNotSent( 'orders.create' );
        expect( $cart->refresh()->payment_reference )->toBeNull();
    } );

    it( 'removes a saved card PayPal rejects, and says so', function (): void {
        [ $customer, $cart ] = apiShopperCart( 'paypal-card' );
        $card                = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-dead' ] );
        $this->paypal->respond( 'orders.create', Orders::issue( 'TOKEN_ID_NOT_FOUND' ), 422 );

        sessionWithOptions( $cart, [ PayPalGateway::SAVED_METHOD_OPTION => $card->id ] )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.0.code', PayPalSavedMethodException::INVALID );

        // Removed although the checkout's transaction rolled back.
        expect( $card->refresh()->trashed() )->toBeTrue();
    } );

    it( 'refuses options from a guest, and options a gateway doesn\'t take', function ( Closure $setUp, array $options ): void {
        $cart = $setUp();

        sessionWithOptions( $cart, $options )
            ->assertStatus( 422 )
            ->assertJsonPath( 'errors.0.code', 'payment-options-invalid' );

        $this->paypal->assertNotSent( 'orders.create' );
    } )->with( [
        'guest saving'             => [ fn (): Cart => readyForSession( PayPalWebhooks::pendingCart( 'paypal-card' ) ), [ PayPalGateway::SAVE_OPTION => true ] ],
        'saved card on PayPal'     => [ fn (): Cart => apiShopperCart( 'paypal' )[1], [ PayPalGateway::SAVED_METHOD_OPTION => 1 ] ],
        'saving on Apple Pay'      => [ fn (): Cart => apiShopperCart( 'paypal-apple-pay' )[1], [ PayPalGateway::SAVE_OPTION => true ] ],
        'an unknown option'        => [ fn (): Cart => apiShopperCart()[1], [ 'colour' => 'red' ] ],
        'a saved card that isn\'t' => [ fn (): Cart => apiShopperCart()[1], [ PayPalGateway::SAVED_METHOD_OPTION => 'abc' ] ],
    ] );

    it( 'reuses the Order while the options stay the same, and replaces it when they change', function (): void {
        [ , $cart ] = apiShopperCart( 'paypal-card' );
        answerOrders( $this->paypal, 8 );

        sessionWithOptions( $cart, [ PayPalGateway::SAVE_OPTION => true ] )->assertOk();
        sessionWithOptions( $cart, [ PayPalGateway::SAVE_OPTION => true ] )->assertOk();
        sessionWithOptions( $cart, null )->assertOk();

        expect( createdOrders( $this->paypal ) )->toHaveCount( 1 );

        sessionWithOptions( $cart, [ PayPalGateway::SAVE_OPTION => false ] )->assertOk();

        $orders = createdOrders( $this->paypal );

        expect( $orders )->toHaveCount( 2 )
            ->and( $orders[1]['payment_source']['card']['attributes'] ?? [] )->not->toHaveKey( 'vault' )
            ->and( app( PayPalVault::class )->wantsToSave( $cart->refresh() ) )->toBeFalse();
    } );

    it( 'replaces the Order when the shopper picks a saved card after a new one', function (): void {
        [ $customer, $cart ] = apiShopperCart( 'paypal-card' );
        $card                = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-saved' ] );
        answerOrders( $this->paypal, 8 );

        sessionWithOptions( $cart, [] )->assertOk();
        sessionWithOptions( $cart, [ PayPalGateway::SAVED_METHOD_OPTION => $card->id ] )->assertOk();
        sessionWithOptions( $cart, [ PayPalGateway::SAVED_METHOD_OPTION => $card->id ] )->assertOk();

        $orders = createdOrders( $this->paypal );

        expect( $orders )->toHaveCount( 2 )
            ->and( $orders[1]['payment_source']['card']['vault_id'] ?? null )->toBe( 'tok-saved' );
    } );
} );
