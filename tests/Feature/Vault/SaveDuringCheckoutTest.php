<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalButtons;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalCardFields;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

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
 * @return ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway
 */
function vaultGateway( string $key ): ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway
{
    return app( PaymentGatewayRegistry::class )->get( $key );
}

/**
 * The payment source of the Order body built for `$cart`.
 *
 * @param  Cart    $cart  Cart.
 * @param  string  $key   Gateway key.
 *
 * @return array<string, mixed>
 */
function vaultPaymentSource( Cart $cart, string $key ): array
{
    $source = vaultGateway( $key )->paymentSource();

    return app( OrderPayloadBuilder::class )->build( $cart->load( 'items' ), vaultGateway( $key ) )['payment_source'][ $source ] ?? [];
}

describe( 'order payload', function (): void {
    it( 'asks PayPal to vault a PayPal wallet the shopper chose to save', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn() );

        app( PayPalVault::class )->rememberChoice( $cart, true );

        expect( vaultPaymentSource( $cart, 'paypal' )['attributes'] )->toBe( [
            'vault' => [ 'store_in_vault' => 'ON_SUCCESS', 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER' ],
        ] );
    } );

    it( 'asks PayPal to vault a card, keeping the 3-D Secure method', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-card' );

        app( PayPalVault::class )->rememberChoice( $cart, true );

        expect( vaultPaymentSource( $cart, 'paypal-card' )['attributes'] )->toBe( [
            'verification' => [ 'method' => 'SCA_WHEN_REQUIRED' ],
            'vault'        => [ 'store_in_vault' => 'ON_SUCCESS' ],
        ] );
    } );

    it( 'sends the PayPal customer id of a returning buyer', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer );

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'PAYPAL-CUS' ] );
        PayPalCustomer::factory()->live()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'LIVE-CUS' ] );
        app( PayPalVault::class )->rememberChoice( $cart, true );

        expect( vaultPaymentSource( $cart, 'paypal' )['attributes']['customer'] )->toBe( [ 'id' => 'PAYPAL-CUS' ] );
    } );

    it( 'doesn\'t vault when the shopper didn\'t ask, isn\'t the cart\'s owner, or is a guest', function ( Cart $cart ): void {
        expect( vaultPaymentSource( $cart, 'paypal' ) )->not->toHaveKey( 'attributes' );
    } )->with( [
        'not chosen'   => fn (): Cart => Shoppers::cart( Shoppers::signIn() ),
        'other owner'  => function (): Cart {
            $cart = Shoppers::cart( Customer::factory()->create() );
            Shoppers::signIn();
            app( PayPalVault::class )->rememberChoice( $cart, true );

            return $cart;
        },
        'guest'        => function (): Cart {
            $cart = Shoppers::cart( Customer::factory()->create() );
            app( PayPalVault::class )->rememberChoice( $cart, true );

            return $cart;
        },
        'vault off'    => function (): Cart {
            $cart = Shoppers::cart( Shoppers::signIn() );
            app( PayPalVault::class )->rememberChoice( $cart, true );
            config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

            return $cart;
        },
    ] );

    it( 'never vaults Apple Pay or Google Pay', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-apple-pay' );

        app( PayPalVault::class )->rememberChoice( $cart, true );

        expect( app( OrderPayloadBuilder::class )->build( $cart->load( 'items' ), vaultGateway( PayPalApplePayGateway::KEY ) ) )->not->toHaveKey( 'payment_source' );
    } );

    it( 'uses a new request id when the save choice changes, so PayPal makes a new Order', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn() );
        $cart->forceFill( [ 'payment_reference' => null ] )->save();

        vaultGateway( 'paypal' )->createPaymentSession( $cart->load( 'items' ) );
        app( PayPalVault::class )->rememberChoice( $cart, true );
        vaultGateway( 'paypal' )->createPaymentSession( $cart->load( 'items' ) );

        $ids = array_map( static fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'orders.create' ) );

        expect( $ids )->toHaveCount( 2 )->and( $ids[0] )->not->toBe( $ids[1] );
    } );
} );

describe( 'client config', function (): void {
    it( 'offers saving to a signed-in owner and passes their choice', function ( string $key ): void {
        $cart = Shoppers::cart( Shoppers::signIn(), $key );

        app( PayPalVault::class )->rememberChoice( $cart, true );

        $options = vaultGateway( $key )->clientConfig( $cart, Orders::session( $key ) )['options'];

        expect( $options['vault'] )->toBe( [ 'available' => true, 'save' => true ] )
            ->and( $options )->not->toHaveKey( 'user_id_token' );

        $this->paypal->assertNotSent( 'oauth.token' );
    } )->with( [ 'paypal', 'paypal-card' ] );

    it( 'mints an id token for a buyer with a PayPal customer id', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer );

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'PAYPAL-CUS' ] );

        $options = vaultGateway( 'paypal' )->clientConfig( $cart, Orders::session() )['options'];

        expect( $options['user_id_token'] )->toBe( PayPalFake::fixture( 'oauth/id-token' )['id_token'] );

        $this->paypal->assertSent( 'oauth.token', fn ( Request $request ): bool => str_contains( $request->body(), 'response_type=id_token' ) && str_contains( $request->body(), 'target_customer_id=PAYPAL-CUS' ) );
    } );

    it( 'goes on without an id token when PayPal can\'t mint one', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer );

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id ] );
        $this->paypal->error( 'oauth.token', 'internal-server-error', times: 2 );

        expect( vaultGateway( 'paypal' )->clientConfig( $cart, Orders::session() )['options'] )
            ->not->toHaveKey( 'user_id_token' )
            ->toHaveKey( 'vault' );
    } );

    it( 'never offers saving to guests', function (): void {
        $customer = Customer::factory()->create();
        $cart     = Shoppers::cart( $customer );

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id ] );

        $options = vaultGateway( 'paypal' )->clientConfig( $cart, Orders::session() )['options'];

        expect( Auth::check() )->toBeFalse()
            ->and( $options['vault'] )->toBe( [ 'available' => false, 'save' => false ] )
            ->and( $options )->not->toHaveKey( 'user_id_token' );
    } );

    it( 'leaves vault options out for wallets and when vaulting is off', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-apple-pay' );

        expect( vaultGateway( 'paypal-apple-pay' )->clientConfig( $cart, Orders::session( 'paypal-apple-pay' ) )['options'] )->not->toHaveKey( 'vault' );

        config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

        expect( vaultGateway( 'paypal' )->clientConfig( $cart, Orders::session() )['options'] )->not->toHaveKey( 'vault' );
    } );
} );

describe( 'capture', function (): void {
    it( 'stores the vaulted PayPal wallet and the PayPal customer id', function (): void {
        $customer = Customer::factory()->create();

        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [
            'payment_source.paypal.attributes.vault' => [ 'id' => 'tok-paypal', 'status' => 'VAULTED', 'customer' => [ 'id' => 'PAYPAL-CUS' ] ],
        ] ) );

        vaultGateway( 'paypal' )->capturePayment( Orders::create( [ 'customer_id' => $customer->id ] ), Orders::session() );

        $token = PayPalPaymentToken::query()->sole();

        expect( $token->only( [ 'customer_id', 'paypal_token_id', 'gateway_key', 'type', 'is_default', 'mode' ] ) )->toBe( [
            'customer_id'     => $customer->id,
            'paypal_token_id' => 'tok-paypal',
            'gateway_key'     => PayPalWalletGateway::KEY,
            'type'            => 'paypal',
            'is_default'      => true,
            'mode'            => 'sandbox',
        ] )
            ->and( $token->display )->toBe( [ 'email' => 'buyer@example.com' ] )
            ->and( app( PayPalVault::class )->paypalCustomerId( $customer->id ) )->toBe( 'PAYPAL-CUS' );
    } );

    it( 'stores a vaulted card with display data only', function (): void {
        $customer = Customer::factory()->create();
        $card     = [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12', 'authentication_result' => [ 'liability_shift' => 'POSSIBLE' ], 'attributes' => [ 'vault' => [ 'id' => 'tok-card', 'status' => 'VAULTED', 'customer' => [ 'id' => 'PAYPAL-CUS' ] ] ] ];

        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE' ) );
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [ 'payment_source' => [ 'card' => $card ], 'payer' => null ] ) );

        vaultGateway( PayPalCardGateway::KEY )->capturePayment( Orders::create( [ 'customer_id' => $customer->id, 'payment_gateway_key' => 'paypal-card' ] ), Orders::session( 'paypal-card' ) );

        expect( PayPalPaymentToken::query()->sole()->only( [ 'paypal_token_id', 'gateway_key', 'type', 'display' ] ) )->toBe( [
            'paypal_token_id' => 'tok-card',
            'gateway_key'     => 'paypal-card',
            'type'            => 'card',
            'display'         => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ],
        ] );
    } );

    it( 'stores nothing for a guest order or when PayPal is still vaulting', function ( array $vault, bool $guest ): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [ 'payment_source.paypal.attributes.vault' => $vault ] ) );

        vaultGateway( 'paypal' )->capturePayment( Orders::create( [ 'customer_id' => $guest ? null : Customer::factory()->create()->id ] ), Orders::session() );

        expect( PayPalPaymentToken::query()->count() )->toBe( 0 );
    } )->with( [
        'guest'           => [ [ 'id' => 'tok-paypal', 'status' => 'VAULTED' ], true ],
        'not vaulted yet' => [ [ 'status' => 'APPROVED' ], false ],
    ] );
} );

describe( 'driver', function (): void {
    /**
     * Mounts `$driver` the way the checkout does for `$cart`.
     *
     * @param  class-string  $driver  Driver.
     * @param  Cart          $cart    Cart.
     * @param  string        $key     Gateway key.
     *
     * @return Testable
     */
    function vaultDriver( string $driver, Cart $cart, string $key = 'paypal' ): Testable
    {
        $config = vaultGateway( $key )->clientConfig( $cart, vaultGateway( $key )->retrievePaymentSession( (string) $cart->payment_reference ) );

        return Livewire::test( $driver, [ 'config' => $config, 'gateway' => $key, 'gatewayLabel' => 'PayPal', 'reference' => (string) $cart->payment_reference, 'token' => 'action-token' ] );
    }

    it( 'shows the save choice to a signed-in owner', function ( string $driver, string $key, string $label ): void {
        $cart = Shoppers::cart( Shoppers::signIn(), $key );

        vaultDriver( $driver, $cart, $key )
            ->assertSeeHtml( 'data-paypal-save-method' )
            ->assertSee( $label )
            ->assertSet( 'saveMethod', false );
    } )->with( [
        'buttons'     => [ PayPalButtons::class, 'paypal', 'Save PayPal for faster checkout' ],
        'card fields' => [ PayPalCardFields::class, 'paypal-card', 'Save this card for faster checkout' ],
    ] );

    it( 'hides the save choice from guests', function (): void {
        $cart = Shoppers::cart( Customer::factory()->create() );

        vaultDriver( PayPalButtons::class, $cart )->assertDontSeeHtml( 'data-paypal-save-method' );
    } );

    it( 'replaces the Order when the shopper ticks save, and back again', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn() );

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'id' => 'SAVED-ORDER' ] ) );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/created', [ 'id' => 'SAVED-ORDER', 'purchase_units.0.amount.value' => '25.00' ] ) );

        $component = vaultDriver( PayPalButtons::class, $cart )
            ->set( 'saveMethod', true )
            ->assertSet( 'reference', 'SAVED-ORDER' )
            ->assertDispatched( 'paypal-order-changed', orderId: 'SAVED-ORDER' )
            ->assertNotDispatched( 'payment-failed' );

        expect( $cart->refresh()->payment_reference )->toBe( 'SAVED-ORDER' )
            ->and( app( PayPalVault::class )->wantsToSave( $cart ) )->toBeTrue()
            ->and( $component->get( 'config' )['client_secret'] )->toBe( 'SAVED-ORDER' )
            ->and( $component->get( 'config' )['options']['vault'] )->toBe( [ 'available' => true, 'save' => true ] );

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => 'ON_SUCCESS' === data_get( $request->data(), 'payment_source.paypal.attributes.vault.store_in_vault' ) );

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'id' => 'PLAIN-ORDER' ] ) );

        $component->set( 'saveMethod', false )->assertSet( 'reference', 'PLAIN-ORDER' );

        expect( app( PayPalVault::class )->wantsToSave( $cart->refresh() ) )->toBeFalse()
            ->and( $cart->payment_reference )->toBe( 'PLAIN-ORDER' );
    } );

    it( 'puts the old Order back when PayPal can\'t create the new one', function (): void {
        $cart = Shoppers::cart( Shoppers::signIn() );

        $this->paypal->respond( 'orders.get', Orders::body( 'orders/created', [ 'purchase_units.0.amount.value' => '25.00' ] ) );
        $this->paypal->error( 'orders.create', 'internal-server-error' );

        vaultDriver( PayPalButtons::class, $cart )
            ->set( 'saveMethod', true )
            ->assertSet( 'saveMethod', false )
            ->assertSet( 'reference', Orders::ORDER_ID )
            ->assertDispatched( 'payment-failed' )
            ->assertNotDispatched( 'paypal-order-changed' );

        expect( $cart->refresh()->payment_reference )->toBe( Orders::ORDER_ID )
            ->and( app( PayPalVault::class )->wantsToSave( $cart ) )->toBeFalse();
    } );

    it( 'refuses the choice when saving isn\'t offered', function (): void {
        $cart = Shoppers::cart( Customer::factory()->create() );

        vaultDriver( PayPalButtons::class, $cart )
            ->set( 'saveMethod', true )
            ->assertSet( 'saveMethod', false )
            ->assertNotDispatched( 'paypal-order-changed' );

        $this->paypal->assertNotSent( 'orders.create' );
    } );

    it( 'refuses the choice for a cart the shopper doesn\'t own', function (): void {
        $cart      = Shoppers::cart( Shoppers::signIn() );
        $component = vaultDriver( PayPalButtons::class, $cart );

        $cart->forceFill( [ 'customer_id' => Customer::factory()->create()->id ] )->save();

        $component->set( 'saveMethod', true )
            ->assertSet( 'saveMethod', false )
            ->assertDispatched( 'payment-failed' );

        $this->paypal->assertNotSent( 'orders.create' );
    } );
} );
