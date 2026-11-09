<?php

/**
 * PayPal webhook deliveries for tests.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ecommerce\Checkout\CheckoutState;
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
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Posts PayPal events through the engine's `WebhookController`
 * (`POST ecommerce/webhooks/{provider}`), verified in `postback` mode
 * against {@see \ArtisanPackUI\EcommercePayPal\Testing\PayPalFake}'s
 * `verify-webhook-signature` answer, and builds checkout-ready carts for
 * them to settle.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWebhooks
{
    /**
     * Sets the config a verified delivery needs.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function configure(): void
    {
        config( [
            'artisanpack.ecommerce-paypal.webhook_id'           => 'WH-TEST',
            'artisanpack.ecommerce-paypal.webhook_verification' => 'postback',
        ] );
    }

    /**
     * Re-boots the package with PayPal enabled (and `$config` applied)
     * against fresh registries, so the four gateways are registered.
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
            'client_id'                   => 'client-id',
            'client_secret'               => 'client-secret',
            'http.retries'                => 0,
            'methods.card.enabled'        => true,
            'methods.apple_pay.enabled'   => true,
            'methods.google_pay.enabled'  => true,
        ];

        foreach ( array_merge( $defaults, $config ) as $key => $value ) {
            config( [ 'artisanpack.ecommerce-paypal.' . $key => $value ] );
        }

        self::configure();

        $app = app();

        foreach ( [ SatelliteRegistry::class, SettingsRegistry::class, PaymentGatewayRegistry::class ] as $registry ) {
            $app->forgetInstance( $registry );
            $app->singleton( $registry, static fn ( $app ) => new $registry( $app ) );
        }

        ( new EcommercePayPalServiceProvider( $app ) )->boot();
    }

    /**
     * Posts an event.
     *
     * @since 1.0.0
     *
     * @param  TestCase              $test      Test case.
     * @param  string                $type      Event type.
     * @param  array<string, mixed>  $resource  Event resource.
     * @param  string                $eventId   Event id.
     * @param  string                $provider  `{provider}` segment.
     *
     * @return TestResponse
     */
    public static function post( TestCase $test, string $type, array $resource, string $eventId = 'WH-EVENT-1', string $provider = 'paypal' ): TestResponse
    {
        $body = (string) json_encode( [
            'id'            => $eventId,
            'event_version' => '1.0',
            'create_time'   => CarbonImmutable::now()->utc()->format( 'Y-m-d\TH:i:s\Z' ),
            'event_type'    => $type,
            'resource'      => $resource,
        ], JSON_THROW_ON_ERROR );

        return $test->call( 'POST', '/ecommerce/webhooks/' . $provider, [], [], [], [
            'CONTENT_TYPE'                  => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID'   => 'b2384410-f8d2-11e8-8f6a-' . substr( md5( $eventId ), 0, 12 ),
            'HTTP_PAYPAL_TRANSMISSION_TIME' => CarbonImmutable::now()->utc()->format( 'Y-m-d\TH:i:s\Z' ),
            'HTTP_PAYPAL_TRANSMISSION_SIG'  => base64_encode( 'signature' ),
            'HTTP_PAYPAL_CERT_URL'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-test',
            'HTTP_PAYPAL_AUTH_ALGO'         => 'SHA256withRSA',
        ], $body );
    }

    /**
     * A capture resource as PayPal posts it.
     *
     * @since 1.0.0
     *
     * @param  string  $status  Capture status.
     * @param  string  $value   Amount.
     *
     * @return array<string, mixed>
     */
    public static function capture( string $status = 'COMPLETED', string $value = '25.00' ): array
    {
        return [
            'id'                 => PayPalTestOrders::CAPTURE_ID,
            'status'             => $status,
            'amount'             => [ 'currency_code' => 'USD', 'value' => $value ],
            'supplementary_data' => [ 'related_ids' => [ 'order_id' => PayPalTestOrders::ORDER_ID ] ],
        ];
    }

    /**
     * A refund resource as PayPal posts it.
     *
     * @since 1.0.0
     *
     * @param  string  $refundId  Refund id.
     *
     * @return array<string, mixed>
     */
    public static function refund( string $refundId = '1JU08902781691411' ): array
    {
        return [
            'id'     => $refundId,
            'status' => 'COMPLETED',
            'amount' => [ 'currency_code' => 'USD', 'value' => '10.00' ],
            'links'  => [ [ 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/' . PayPalTestOrders::CAPTURE_ID, 'rel' => 'up', 'method' => 'GET' ] ],
        ];
    }

    /**
     * A cart holding one $20.00 product with $5.00 shipping, paid with
     * `$gatewayKey` through the fixture PayPal Order and waiting on the
     * payment.
     *
     * @since 1.0.0
     *
     * @param  string  $gatewayKey  Gateway key.
     *
     * @return Cart
     */
    public static function pendingCart( string $gatewayKey ): Cart
    {
        $product = Product::factory()->create();
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 2_000 ] );

        $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ], 'position' => 0 ] );

        $carts    = app( StorefrontCartService::class );
        $checkout = app( CheckoutService::class );
        $cart     = $carts->create( 'USD' );

        $carts->addItem( $cart, $product->id, null, 1 );
        $checkout->setEmail( $cart, 'ada@example.test' );
        $checkout->setAddress( $cart, new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', firstName: 'Ada', lastName: 'Lovelace', regionCode: 'IL', postalCode: '60601' ) );
        $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );
        $checkout->setPaymentGateway( $cart, $gatewayKey );

        $cart->refresh()->forceFill( [
            'payment_gateway_key' => $gatewayKey,
            'payment_reference'   => PayPalTestOrders::ORDER_ID,
            'checkout_state'      => CheckoutState::PAYMENT_PENDING,
        ] )->save();

        return $cart->refresh();
    }
}
