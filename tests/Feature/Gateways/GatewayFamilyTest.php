<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Money\Currency;
use Money\Money;
use Tests\Support\CartBuilder;

uses( RefreshDatabase::class );

/**
 * Re-boots the package with `$config` against fresh registries.
 *
 * @param  array<string, mixed>  $config  Config under `artisanpack.ecommerce-paypal`.
 *
 * @return PaymentGatewayRegistry
 */
function bootPayPal( array $config = [] ): PaymentGatewayRegistry
{
    $defaults = [ 'enabled' => true, 'client_id' => 'client-id', 'client_secret' => 'client-secret' ];

    foreach ( array_merge( $defaults, $config ) as $key => $value ) {
        config( [ 'artisanpack.ecommerce-paypal.' . $key => $value ] );
    }

    $app = app();

    foreach ( [ SatelliteRegistry::class, SettingsRegistry::class, PaymentGatewayRegistry::class ] as $registry ) {
        $app->forgetInstance( $registry );
        $app->singleton( $registry, static fn ( $app ) => new $registry( $app ) );
    }

    ( new EcommercePayPalServiceProvider( $app ) )->boot();

    return $app->make( PaymentGatewayRegistry::class );
}

const PAYPAL_KEYS = [ 'paypal', 'paypal-card', 'paypal-apple-pay', 'paypal-google-pay' ];

describe( 'registration', function (): void {
    it( 'registers the four gateways when enabled with credentials', function (): void {
        $registry = bootPayPal();

        foreach ( PAYPAL_KEYS as $key ) {
            expect( $registry->has( $key ) )->toBeTrue()
                ->and( $registry->get( $key )->key() )->toBe( $key );
        }
    } );

    it( 'registers nothing without the package enabled or credentials', function ( array $config ): void {
        $registry = bootPayPal( $config );

        foreach ( PAYPAL_KEYS as $key ) {
            expect( $registry->has( $key ) )->toBeFalse();
        }
    } )->with( [
        'disabled'       => [ [ 'enabled' => false ] ],
        'no client id'   => [ [ 'client_id' => null ] ],
        'no secret'      => [ [ 'client_secret' => '' ] ],
        'blank id'       => [ [ 'client_id' => '   ' ] ],
    ] );

    it( 'registers a switched-off method so its orders can still be refunded', function (): void {
        $registry = bootPayPal( [ 'methods.card.enabled' => false ] );

        expect( $registry->has( 'paypal-card' ) )->toBeTrue();
    } );

    it( 'implements the engine contracts with the spec labels and drivers', function ( string $class, string $key, string $label, string $source, string $driver ): void {
        $gateway = app( $class );

        expect( $gateway )->toBeInstanceOf( PaymentGateway::class )->toBeInstanceOf( RendersClientPayment::class )
            ->and( $gateway->key() )->toBe( $key )
            ->and( $gateway->label() )->toBe( $label )
            ->and( $gateway->paymentSource() )->toBe( $source )
            ->and( $gateway->driver() )->toBe( $driver )
            ->and( $gateway->supportsRefunds() )->toBeTrue()
            ->and( $gateway->supportsPartialRefunds() )->toBeTrue();
    } )->with( [
        [ PayPalWalletGateway::class, 'paypal', 'PayPal', 'paypal', 'paypal-buttons' ],
        [ PayPalCardGateway::class, 'paypal-card', 'Credit or debit card', 'card', 'paypal-card-fields' ],
        [ PayPalApplePayGateway::class, 'paypal-apple-pay', 'Apple Pay', 'apple_pay', 'paypal-apple-pay' ],
        [ PayPalGooglePayGateway::class, 'paypal-google-pay', 'Google Pay', 'google_pay', 'paypal-google-pay' ],
    ] );

    it( 'supports saved instruments for PayPal and cards only while vaulting is on', function ( bool $vault ): void {
        config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => $vault ] );

        expect( app( PayPalWalletGateway::class )->supportsSavedInstruments() )->toBe( $vault )
            ->and( app( PayPalCardGateway::class )->supportsSavedInstruments() )->toBe( $vault )
            ->and( app( PayPalApplePayGateway::class )->supportsSavedInstruments() )->toBeFalse()
            ->and( app( PayPalGooglePayGateway::class )->supportsSavedInstruments() )->toBeFalse();
    } )->with( [ 'vault on' => true, 'vault off' => false ] );
} );

describe( 'availability', function (): void {
    it( 'offers each method only in the currencies PayPal takes for it', function ( string $currency, array $expected ): void {
        $registry = bootPayPal( [
            'methods.paypal.enabled'     => true,
            'methods.card.enabled'       => true,
            'methods.apple_pay.enabled'  => true,
            'methods.google_pay.enabled' => true,
        ] );

        $cart = CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ], [ 'currency' => $currency ] );

        expect( array_keys( $registry->availableFor( $cart ) ) )->toBe( $expected );
    } )->with( [
        'USD' => [ 'USD', PAYPAL_KEYS ],
        'HUF' => [ 'HUF', PAYPAL_KEYS ],
        'BRL' => [ 'BRL', [ 'paypal', 'paypal-apple-pay', 'paypal-google-pay' ] ],
        'CNY' => [ 'CNY', [ 'paypal' ] ],
        'XYZ' => [ 'XYZ', [] ],
    ] );

    it( 'offers only the enabled methods', function (): void {
        $registry = bootPayPal( [ 'methods.paypal.enabled' => true, 'methods.card.enabled' => true, 'methods.apple_pay.enabled' => false, 'methods.google_pay.enabled' => false ] );

        expect( array_keys( $registry->availableFor( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) ) ) )->toBe( [ 'paypal', 'paypal-card' ] );
    } );

    it( 'hides every method when there is nothing to pay', function (): void {
        $registry = bootPayPal( [ 'methods.card.enabled' => true ] );

        expect( $registry->availableFor( CartBuilder::make( [ [ 'Gift', 0, 1 ] ] ) ) )->toBe( [] );
    } );

    it( 'hides every method when the total rounds to nothing in PayPal units', function (): void {
        $registry = bootPayPal( [ 'methods.paypal.enabled' => true, 'methods.card.enabled' => true ] );

        expect( $registry->availableFor( CartBuilder::make( [ [ 'Sticker', 40, 1 ] ], [ 'currency' => 'HUF' ] ) ) )->toBe( [] )
            ->and( array_keys( $registry->availableFor( CartBuilder::make( [ [ 'Sticker', 100, 1 ] ], [ 'currency' => 'HUF' ] ) ) ) )->toBe( [ 'paypal', 'paypal-card' ] );
    } );

    it( 'hides every method when a HUF total is not a whole forint, so PayPal never rounds it', function (): void {
        $registry = bootPayPal( [
            'methods.paypal.enabled'     => true,
            'methods.card.enabled'       => true,
            'methods.apple_pay.enabled'  => true,
            'methods.google_pay.enabled' => true,
        ] );

        expect( $registry->availableFor( CartBuilder::make( [ [ 'Lamp', 123450, 1 ] ], [ 'currency' => 'HUF' ] ) ) )->toBe( [] )
            ->and( array_keys( $registry->availableFor( CartBuilder::make( [ [ 'Lamp', 123400, 1 ] ], [ 'currency' => 'HUF' ] ) ) ) )->toBe( PAYPAL_KEYS );
    } );

    it( 'leaves other gateways alone', function (): void {
        $registry = bootPayPal();
        $other    = Mockery::mock( PaymentGateway::class );
        $other->shouldReceive( 'key' )->andReturn( 'manual' );
        $registry->register( 'manual', $other );

        expect( array_keys( $registry->availableFor( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ], [ 'currency' => 'XYZ' ] ) ) ) )->toBe( [ 'manual' ] );
    } );
} );

describe( 'webhooks', function (): void {
    it( 'refuses webhooks posted to a sibling key', function ( string $class ): void {
        $result = app( $class )->handleWebhook( Request::create( '/ecommerce/webhooks/x', 'POST', content: '{}' ) );

        expect( $result->verified )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'use_paypal_endpoint' );
    } )->with( [ PayPalCardGateway::class, PayPalApplePayGateway::class, PayPalGooglePayGateway::class ] );

    it( 'does not trust webhooks to the paypal key until verification lands', function (): void {
        expect( app( PayPalWalletGateway::class )->handleWebhook( Request::create( '/ecommerce/webhooks/paypal', 'POST', content: '{}' ) )->verified )->toBeFalse();
    } );

    it( 'vouches for the sibling keys', function (): void {
        $gateway = app( PayPalWalletGateway::class );

        expect( $gateway )->toBeInstanceOf( SharesWebhookEndpoint::class )
            ->and( $gateway->webhookGatewayKeys() )->toBe( PAYPAL_KEYS )
            ->and( PayPalCardGateway::class )->not->toImplement( SharesWebhookEndpoint::class );
    } );

    it( 'lets the engine reconcile the registered siblings from the paypal endpoint', function (): void {
        bootPayPal();

        expect( app( PaymentReconciler::class )->webhookGatewayKeys( 'paypal' ) )->toBe( PAYPAL_KEYS )
            ->and( app( PaymentReconciler::class )->webhookGatewayKeys( 'paypal-card' ) )->toBe( [ 'paypal-card' ] );
    } );
} );

describe( 'status mapping', function (): void {
    it( 'normalizes PayPal Order states', function ( string $fixtureOrStatus, ?string $expected ): void {
        $order = str_contains( $fixtureOrStatus, '/' )
            ? PayPalOrder::fromArray( PayPalFake::fixture( $fixtureOrStatus ) )
            : PayPalOrder::fromArray( [ 'id' => 'X', 'status' => $fixtureOrStatus ] );

        expect( app( PayPalWalletGateway::class )->sessionStatus( $order ) )->toBe( $expected );
    } )->with( [
        'created'               => [ 'CREATED', PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD ],
        'saved'                 => [ 'SAVED', PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD ],
        'payer action required' => [ 'PAYER_ACTION_REQUIRED', PaymentSession::STATUS_REQUIRES_ACTION ],
        'approved'              => [ 'APPROVED', PaymentSession::STATUS_AUTHORIZED ],
        'voided'                => [ 'VOIDED', PaymentSession::STATUS_CANCELED ],
        'captured'              => [ 'orders/captured', PaymentSession::STATUS_SUCCEEDED ],
        'authorized'            => [ 'orders/authorized', PaymentSession::STATUS_AUTHORIZED ],
        'completed, nothing'    => [ 'COMPLETED', null ],
        'unknown'               => [ 'MYSTERY', null ],
    ] );

    it( 'maps a pending capture to processing', function (): void {
        $order                                                           = PayPalFake::fixture( 'orders/captured' );
        $order['purchase_units'][0]['payments']['captures'][0]['status'] = 'PENDING';

        expect( app( PayPalWalletGateway::class )->sessionStatus( PayPalOrder::fromArray( $order ) ) )->toBe( PaymentSession::STATUS_PROCESSING );
    } );

    it( 'uses the newest settled capture, not the last one listed', function (): void {
        $order = PayPalFake::fixture( 'orders/captured' );

        $order['purchase_units'][0]['payments']['captures'] = [
            [ 'id' => 'NEWER', 'status' => 'COMPLETED', 'create_time' => '2026-11-09T12:05:00Z' ],
            [ 'id' => 'OLDER', 'status' => 'PENDING', 'create_time' => '2026-11-09T12:00:00Z' ],
        ];

        $paypalOrder = PayPalOrder::fromArray( $order );

        expect( $paypalOrder->latestCapture()?->id() )->toBe( 'NEWER' )
            ->and( app( PayPalWalletGateway::class )->sessionStatus( $paypalOrder ) )->toBe( PaymentSession::STATUS_SUCCEEDED );
    } );

    it( 'falls back to the newest capture when none is settled', function (): void {
        $order = PayPalFake::fixture( 'orders/captured' );

        $order['purchase_units'][0]['payments']['captures'] = [
            [ 'id' => 'NEWER', 'status' => 'PENDING', 'create_time' => '2026-11-09T12:05:00Z' ],
            [ 'id' => 'OLDER', 'status' => 'DECLINED', 'create_time' => '2026-11-09T12:00:00Z' ],
        ];

        expect( PayPalOrder::fromArray( $order )->latestCapture()?->id() )->toBe( 'NEWER' );
    } );
} );

describe( 'client config', function (): void {
    beforeEach( function (): void {
        config( [
            'artisanpack.ecommerce-paypal.client_id'                  => 'public-client-id',
            'artisanpack.ecommerce-paypal.methods.paypal.enabled'     => true,
            'artisanpack.ecommerce-paypal.methods.paypal.pay_later'   => true,
            'artisanpack.ecommerce-paypal.methods.paypal.venmo'       => false,
            'artisanpack.ecommerce-paypal.methods.card.enabled'       => true,
            'artisanpack.ecommerce-paypal.methods.apple_pay.enabled'  => false,
            'artisanpack.ecommerce-paypal.methods.google_pay.enabled' => true,
            'artisanpack.ecommerce-paypal.pay_later_messaging'        => [ 'checkout' => true, 'cart' => false, 'product' => false ],
        ] );
    } );

    it( 'describes the browser step with the Order as the client secret', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.intent' => 'authorize' ] );
        app()->setLocale( 'fr' );

        $session = new PaymentSession( 'paypal-card', 'ORDER-1', new Money( 1000, new Currency( 'EUR' ) ) );
        $config  = app( PayPalCardGateway::class )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session );

        expect( $config )->toMatchArray( [
            'driver'          => 'paypal-card-fields',
            'flow'            => 'embedded',
            'publishable_key' => 'public-client-id',
            'client_secret'   => 'ORDER-1',
        ] )
            ->and( $config['options'] )->toBe( [
                'order_id'                => 'ORDER-1',
                'amount'                  => '10.00',
                'restart'                 => false,
                'currency'                => 'EUR',
                'intent'                  => 'authorize',
                'mode'                    => 'sandbox',
                'locale'                  => 'fr_FR',
                'components'              => [ 'buttons', 'messages', 'card-fields', 'googlepay' ],
                'enable_funding'          => [ 'paylater' ],
                'disable_funding'         => [ 'venmo', 'card' ],
                'three_d_secure'          => 'SCA_WHEN_REQUIRED',
                'require_liability_shift' => true,
            ] );
    } );

    it( 'derives the SDK locale from the app locale, falling back to en_US', function ( string $appLocale, string $sdkLocale ): void {
        app()->setLocale( $appLocale );

        $session = new PaymentSession( 'paypal', 'ORDER-1', new Money( 1000, new Currency( 'USD' ) ) );

        expect( app( PayPalWalletGateway::class )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session )['options']['locale'] )->toBe( $sdkLocale );
    } )->with( [
        'day-1 Spanish'      => [ 'es', 'es_ES' ],
        'day-1 French'       => [ 'fr', 'fr_FR' ],
        'day-1 German'       => [ 'de', 'de_DE' ],
        'regional English'   => [ 'en_GB', 'en_GB' ],
        'unsupported locale' => [ 'xx', 'en_US' ],
    ] );

    it( 'gives every gateway the same SDK components, so the SDK loads once', function (): void {
        $session    = new PaymentSession( 'paypal', 'ORDER-1', new Money( 1000, new Currency( 'USD' ) ) );
        $cart       = CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] );
        $components = array_map(
            fn ( string $gateway ): array => app( $gateway )->clientConfig( $cart, $session )['options']['components'],
            [ PayPalWalletGateway::class, PayPalCardGateway::class, PayPalApplePayGateway::class, PayPalGooglePayGateway::class ],
        );

        expect( array_unique( $components, SORT_REGULAR ) )->toHaveCount( 1 );
    } );

    it( 'passes the button style and funding switches to the PayPal buttons', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.paypal.button' => [ 'layout' => 'horizontal', 'color' => 'blue', 'shape' => 'pill', 'label' => 'pay', 'onclick' => 'alert(1)' ] ] );

        $session = new PaymentSession( 'paypal', 'ORDER-1', new Money( 1000, new Currency( 'USD' ) ) );
        $options = app( PayPalWalletGateway::class )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session )['options'];

        expect( $options['button'] )->toBe( [ 'layout' => 'horizontal', 'color' => 'blue', 'shape' => 'pill', 'label' => 'pay' ] )
            ->and( $options['pay_later'] )->toBeTrue()
            ->and( $options['venmo'] )->toBeFalse();
    } );

    it( 'loads only what the enabled methods need', function (): void {
        config( [
            'artisanpack.ecommerce-paypal.methods.paypal.pay_later'   => false,
            'artisanpack.ecommerce-paypal.methods.card.enabled'       => false,
            'artisanpack.ecommerce-paypal.methods.google_pay.enabled' => false,
            'artisanpack.ecommerce-paypal.pay_later_messaging'        => [ 'checkout' => false, 'cart' => false, 'product' => false ],
        ] );

        expect( PayPalWalletGateway::sdkComponents() )->toBe( [ 'buttons' ] )
            ->and( PayPalWalletGateway::disabledFunding() )->toBe( [ 'venmo', 'paylater' ] )
            ->and( PayPalWalletGateway::enabledFunding() )->toBe( [] );
    } );

    it( 'lets a filter add options but not swap the Order or client id', function (): void {
        addFilter( 'ap.ecommerce.paypal.clientOptions', function ( array $options ): array {
            $options['user_id_token'] = 'id-token';
            $options['order_id']      = 'OTHER-ORDER';
            $options['amount']        = '0.01';

            return $options;
        } );

        $session = new PaymentSession( 'paypal', 'ORDER-1', new Money( 1000, new Currency( 'USD' ) ) );
        $config  = app( PayPalWalletGateway::class )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session );

        expect( $config['options']['user_id_token'] )->toBe( 'id-token' )
            ->and( $config['options']['order_id'] )->toBe( 'ORDER-1' )
            ->and( $config['options']['amount'] )->toBe( '10.00' )
            ->and( $config['client_secret'] )->toBe( 'ORDER-1' )
            ->and( $config['publishable_key'] )->toBe( 'public-client-id' );
    } );

    it( 'never includes the client secret or an access token', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'super-secret-value' ] );

        $session = new PaymentSession( 'paypal', 'ORDER-1', new Money( 1000, new Currency( 'USD' ) ) );

        foreach ( [ PayPalWalletGateway::class, PayPalCardGateway::class, PayPalApplePayGateway::class, PayPalGooglePayGateway::class ] as $gateway ) {
            $json = (string) json_encode( app( $gateway )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session ) );

            expect( $json )->not->toContain( 'super-secret-value' )
                ->and( $json )->not->toContain( 'access_token' );
        }
    } );
} );
