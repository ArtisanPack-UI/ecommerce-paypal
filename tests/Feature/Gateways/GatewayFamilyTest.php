<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Services\PaymentReconciler;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Http\Request;
use Money\Currency;
use Money\Money;
use Tests\Support\CartBuilder;

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
} );

describe( 'client config and pending operations', function (): void {
    it( 'describes the browser step', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.client_id' => 'public-client-id', 'artisanpack.ecommerce-paypal.intent' => 'authorize' ] );

        $session = new PaymentSession( 'paypal-card', 'ORDER-1', new Money( 1000, new Currency( 'EUR' ) ) );
        $config  = app( PayPalCardGateway::class )->clientConfig( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), $session );

        expect( $config )->toMatchArray( [
            'driver'          => 'paypal-card-fields',
            'flow'            => 'embedded',
            'publishable_key' => 'public-client-id',
            'client_secret'   => null,
        ] )
            ->and( $config['options'] )->toBe( [ 'order_id' => 'ORDER-1', 'currency' => 'EUR', 'intent' => 'authorize', 'mode' => 'sandbox' ] )
            ->and( json_encode( $config ) )->not->toContain( 'client-secret' );
    } );

    it( 'refuses payment operations until they are built', function ( Closure $call ): void {
        $call( app( PayPalWalletGateway::class ) );
    } )->with( [
        'create'   => [ fn ( PayPalGateway $gateway ) => $gateway->createPaymentSession( CartBuilder::make( [] ) ) ],
        'retrieve' => [ fn ( PayPalGateway $gateway ) => $gateway->retrievePaymentSession( 'X' ) ],
        'capture'  => [ fn ( PayPalGateway $gateway ) => $gateway->capturePayment( new Order(), new PaymentSession( 'paypal', 'X', new Money( 1, new Currency( 'USD' ) ) ) ) ],
        'void'     => [ fn ( PayPalGateway $gateway ) => $gateway->voidPendingPayment( new Order() ) ],
        'refund'   => [ fn ( PayPalGateway $gateway ) => $gateway->refund( new Order(), new Money( 1, new Currency( 'USD' ) ) ) ],
    ] )->throws( LogicException::class );
} );
