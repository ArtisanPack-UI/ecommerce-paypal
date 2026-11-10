<?php

/**
 * PayPal sandbox helpers for the sandbox integration suite.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Sandbox\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalWebhookEvent;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the package against the real PayPal sandbox (spec §12.3).
 *
 * The suite needs `PAYPAL_SANDBOX_CLIENT_ID` and
 * `PAYPAL_SANDBOX_CLIENT_SECRET` (a sandbox REST app with advanced card
 * payments on). Without them every test is skipped. Webhook events only
 * exist for an app with a webhook subscribed to them, so the app needs
 * one (any HTTPS URL; PayPal never has to reach it).
 *
 * Card data is put on the Order here, through the
 * `ap.ecommerce.paypal.orderPayload` filter, only because CI has no
 * browser to fill PayPal's hosted fields. Package code never sends card
 * data.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalSandbox
{
    /**
     * The default sandbox card: a Visa PayPal's API reference uses, which
     * the sandbox approves without 3-D Secure.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEFAULT_CARD = '4111111111111111';

    /**
     * How long to wait for PayPal to list an event, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EVENT_TIMEOUT = 120;

    /**
     * Whether the sandbox credentials are set.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function configured(): bool
    {
        return '' !== self::env( 'PAYPAL_SANDBOX_CLIENT_ID' ) && '' !== self::env( 'PAYPAL_SANDBOX_CLIENT_SECRET' );
    }

    /**
     * The reason a test is skipped without credentials.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function skipReason(): string
    {
        return 'Set PAYPAL_SANDBOX_CLIENT_ID and PAYPAL_SANDBOX_CLIENT_SECRET to run the PayPal sandbox suite.';
    }

    /**
     * An environment value ('' when unset).
     *
     * @since 1.0.0
     *
     * @param  string  $key  Variable name.
     *
     * @return string
     */
    public static function env( string $key ): string
    {
        $value = getenv( $key );

        if ( false === $value ) {
            $value = $_ENV[ $key ] ?? $_SERVER[ $key ] ?? '';
        }

        return trim( is_string( $value ) ? $value : '' );
    }

    /**
     * Boots the package against the sandbox with `$config` applied, on
     * fresh registries, so the gateways register with the real credentials.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $config  Config under `artisanpack.ecommerce-paypal`.
     *
     * @return void
     */
    public static function boot( array $config = [] ): void
    {
        $defaults = [
            'enabled'                     => true,
            'mode'                        => 'sandbox',
            'client_id'                   => self::env( 'PAYPAL_SANDBOX_CLIENT_ID' ),
            'client_secret'               => self::env( 'PAYPAL_SANDBOX_CLIENT_SECRET' ),
            'intent'                      => 'capture',
            'methods.card.enabled'        => true,
            'methods.card.three_d_secure' => 'SCA_WHEN_REQUIRED',
            'webhook_id'                  => 'WH-SANDBOX-SUITE',
            'webhook_verification'        => 'postback',
            'logging.debug'               => false,
        ];

        foreach ( array_merge( $defaults, $config ) as $key => $value ) {
            config( [ 'artisanpack.ecommerce-paypal.' . $key => $value ] );
        }

        $app = app();

        foreach ( [ SatelliteRegistry::class, SettingsRegistry::class, PaymentGatewayRegistry::class ] as $registry ) {
            $app->forgetInstance( $registry );
            $app->singleton( $registry, static fn ( $app ) => new $registry( $app ) );
        }

        ( new EcommercePayPalServiceProvider( $app ) )->boot();
    }

    /**
     * Puts the sandbox test card on every card-source Order the package
     * creates. Test-only (see the class summary).
     *
     * @since 1.0.0
     *
     * @param  string|null  $number  Card number (default: `PAYPAL_SANDBOX_CARD_NUMBER`, then {@see self::DEFAULT_CARD}).
     *
     * @return void
     */
    public static function payWithTestCard( ?string $number = null ): void
    {
        $number ??= '' !== self::env( 'PAYPAL_SANDBOX_CARD_NUMBER' ) ? self::env( 'PAYPAL_SANDBOX_CARD_NUMBER' ) : self::DEFAULT_CARD;

        addFilter( 'ap.ecommerce.paypal.orderPayload', static function ( array $payload ) use ( $number ): array {
            $card = is_array( $payload['payment_source']['card'] ?? null ) ? $payload['payment_source']['card'] : [];

            $payload['payment_source'] = [
                'card' => array_merge( $card, [
                    'number'          => $number,
                    'expiry'          => CarbonImmutable::now()->addYears( 3 )->format( 'Y-m' ),
                    'security_code'   => '123',
                    'name'            => 'Ada Lovelace',
                    'billing_address' => [
                        'address_line_1' => '1 Main St',
                        'admin_area_2'   => 'Chicago',
                        'admin_area_1'   => 'IL',
                        'postal_code'    => '60601',
                        'country_code'   => 'US',
                    ],
                ] ),
            ];

            return $payload;
        } );
    }

    /**
     * A cart ready to pay with `$gatewayKey`: one $20.00 product, $5.00
     * shipping to Chicago, and a guest email.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     *
     * @return Cart
     */
    public static function cart( string $gatewayKey = 'paypal-card' ): Cart
    {
        $product = Product::factory()->create( [ 'name' => 'Sandbox suite mug' ] );
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 2_000 ] );

        $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ], 'position' => 0 ] );

        $carts    = app( StorefrontCartService::class );
        $checkout = app( CheckoutService::class );
        $cart     = $carts->create( 'USD' );

        $carts->addItem( $cart, $product->id, null, 1 );
        $checkout->setEmail( $cart, 'sandbox-suite@example.test' );
        $checkout->setAddress( $cart, new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', firstName: 'Ada', lastName: 'Lovelace', regionCode: 'IL', postalCode: '60601' ) );
        $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );
        $checkout->setPaymentGateway( $cart, $gatewayKey );

        return $cart->refresh();
    }

    /**
     * The PayPal client the gateways use.
     *
     * @since 1.0.0
     *
     * @return PayPalClient
     */
    public static function client(): PayPalClient
    {
        return app( PayPalClient::class );
    }

    /**
     * Waits for PayPal to list the `$type` event about `$resourceId`.
     *
     * PayPal only records events for an app with a webhook subscribed to
     * them, and lists them a few seconds after they happen.
     *
     * @since 1.0.0
     *
     * @param  string           $type        Event type.
     * @param  string           $resourceId  The resource's id (capture, refund, authorization).
     * @param  CarbonImmutable  $since       Lower bound for the event's time.
     *
     * @throws RuntimeException When the event doesn't arrive in time.
     *
     * @return array<string, mixed> The event as PayPal lists it.
     */
    public static function awaitEvent( string $type, string $resourceId, CarbonImmutable $since ): array
    {
        $deadline = CarbonImmutable::now()->addSeconds( self::EVENT_TIMEOUT );
        $start    = $since->subMinute()->utc()->format( 'Y-m-d\TH:i:s\Z' );

        do {
            foreach ( self::client()->webhooks()->events( $type, $start, null, 100 )->events() as $event ) {
                /** @var PayPalWebhookEvent $event */
                if ( (string) ( $event->resource()['id'] ?? '' ) === $resourceId ) {
                    return $event->raw;
                }
            }

            Sleep::for( 5 )->seconds();
        } while ( CarbonImmutable::now()->lessThan( $deadline ) );

        throw new RuntimeException( sprintf(
            'PayPal did not list a %s event for %s within %d seconds. Check that the sandbox app has a webhook subscribed to it (ecommerce-paypal:webhooks:register).',
            $type,
            $resourceId,
            self::EVENT_TIMEOUT,
        ) );
    }

    /**
     * Posts a listed event through the engine's webhook route
     * (`POST ecommerce/webhooks/paypal`), so it runs through the
     * verifier, the event mapper, and the engine reconciler.
     *
     * The event comes from PayPal's API, not a delivery, so it carries no
     * signature: verification runs in `postback` mode and only PayPal's
     * `verify-webhook-signature` answer is faked. Every other call (the
     * mapper re-fetching the Order, capture, or refund) goes to the
     * sandbox. Real signatures are covered by the recorded deliveries in
     * `tests/Fixtures/signed-deliveries`.
     *
     * @since 1.0.0
     *
     * @param  TestCase              $test   Test case.
     * @param  array<string, mixed>  $event  The event.
     *
     * @return TestResponse
     */
    public static function deliver( TestCase $test, array $event ): TestResponse
    {
        Http::fake( [
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response( [ 'verification_status' => 'SUCCESS' ] ),
        ] );

        $now = CarbonImmutable::now()->utc()->format( 'Y-m-d\TH:i:s\Z' );

        return $test->call( 'POST', '/ecommerce/webhooks/paypal', [], [], [], [
            'CONTENT_TYPE'                  => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID'   => (string) Str::uuid(),
            'HTTP_PAYPAL_TRANSMISSION_TIME' => $now,
            'HTTP_PAYPAL_TRANSMISSION_SIG'  => base64_encode( 'sandbox-suite' ),
            'HTTP_PAYPAL_CERT_URL'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-sandbox-suite',
            'HTTP_PAYPAL_AUTH_ALGO'         => 'SHA256withRSA',
        ], (string) json_encode( $event, JSON_THROW_ON_ERROR ) );
    }
}
