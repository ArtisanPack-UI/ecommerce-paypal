<?php

/**
 * Shared fixtures for the PayPal gateway contract tests.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Testing\Contracts\PaymentGatewayContractTest;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Money\Money;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;
use Tests\TestCase;

/**
 * Runs the engine's `PaymentGatewayContractTest` against a PayPal gateway
 * with {@see PayPalFake} standing in for PayPal (spec §12.2).
 *
 * Each concrete class names its gateway with {@see self::gatewayKey()}, so
 * `ecommerce:verify-satellite` matches it to that registration.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalGatewayContractTestCase extends PaymentGatewayContractTest
{
    /**
     * The fake PayPal API.
     *
     * @since 1.0.0
     *
     * @var PayPalFake
     */
    protected PayPalFake $paypal;

    /**
     * Boots PayPal against the fake.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->paypal = PayPalFake::fake();

        PayPalWebhooks::boot();
    }

    /**
     * The key of the gateway under test.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function gatewayKey(): string;

    /**
     * The gateway under test, as registered.
     *
     * @since 1.0.0
     *
     * @return PaymentGateway
     */
    protected function gateway(): PaymentGateway
    {
        return $this->app->make( PaymentGatewayRegistry::class )->get( $this->gatewayKey() );
    }

    /**
     * A captured order; PayPal answers each of the suite's three partial
     * refunds with the amount asked for.
     *
     * @since 1.0.0
     *
     * @param  string  $currency        Currency.
     * @param  int     $capturedAmount  Captured amount, minor units.
     *
     * @return Order
     */
    protected function makeOrder( string $currency = 'USD', int $capturedAmount = 10_000 ): Order
    {
        foreach ( [ [ 'R-1', '30.00' ], [ 'R-2', '25.00' ], [ 'R-3', '45.00' ] ] as [ $id, $value ] ) {
            $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'id' => $id, 'amount' => [ 'currency_code' => $currency, 'value' => $value ] ] ) );
        }

        return Orders::create(
            [ 'payment_gateway_key' => $this->gatewayKey(), 'currency' => $currency, 'total_amount' => $capturedAmount, 'subtotal_amount' => $capturedAmount, 'payment_status' => 'paid' ],
            [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID, 'capture_status' => 'COMPLETED' ],
        );
    }

    /**
     * An order holding an uncaptured authorization: PayPal voids it the
     * first time and reports it voided the second.
     *
     * @since 1.0.0
     *
     * @return Order
     */
    protected function makePendingAuthorizationOrder(): Order
    {
        $this->paypal->respond( 'payments.authorizations.get', 'payments/authorization' );
        $this->paypal->respond( 'payments.authorizations.void', 'payments/authorization-voided' );
        $this->paypal->respond( 'payments.authorizations.get', 'payments/authorization-voided' );

        return Orders::create(
            [ 'payment_gateway_key' => $this->gatewayKey(), 'payment_status' => 'pending' ],
            [ 'order_id' => Orders::ORDER_ID, 'intent' => 'authorize', 'authorization' => [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'CREATED' ] ],
        );
    }

    /**
     * A PayPal webhook delivery with every signature header (PayPal's
     * postback check, faked, accepts it).
     *
     * @since 1.0.0
     *
     * @return Request
     */
    protected function signedWebhookRequest(): Request
    {
        return self::webhookRequest( true );
    }

    /**
     * The same delivery without its signature.
     *
     * @since 1.0.0
     *
     * @return Request
     */
    protected function unsignedWebhookRequest(): Request
    {
        return self::webhookRequest( false );
    }

    /**
     * A capture PayPal refuses outright (a `422` other than a restartable
     * decline).
     *
     * @since 1.0.0
     *
     * @return PaymentResult
     */
    protected function captureTerminalDeclineResult(): PaymentResult
    {
        $this->paypal->respond( 'orders.capture', Orders::issue( 'TRANSACTION_REFUSED' ), 422 );

        return $this->gateway()->capturePayment( Orders::create( [ 'payment_gateway_key' => $this->gatewayKey() ] ), Orders::session( $this->gatewayKey() ) );
    }

    /**
     * A capture PayPal fails on its side (`500`).
     *
     * @since 1.0.0
     *
     * @return PaymentResult
     */
    protected function captureRetryableResult(): PaymentResult
    {
        $this->paypal->error( 'orders.capture', 'internal-server-error' );

        return $this->gateway()->capturePayment( Orders::create( [ 'payment_gateway_key' => $this->gatewayKey() ] ), Orders::session( $this->gatewayKey() ) );
    }

    /**
     * A session just created for a checkout cart.
     *
     * @since 1.0.0
     *
     * @return PaymentSession
     */
    protected function existingPaymentSession(): PaymentSession
    {
        $cart = PayPalWebhooks::pendingCart( $this->gatewayKey() );
        $cart->forceFill( [ 'payment_reference' => null ] )->save();

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'purchase_units.0.amount.value' => '25.00' ] ) );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'purchase_units.0.amount.value' => '25.00' ] ) );

        $session = $this->gateway()->createPaymentSession( $cart->load( 'items' ) );

        $this->assertTrue( $session->amount->equals( Money::USD( 2_500 ) ) );

        return $session;
    }

    /**
     * Uses in-memory drivers whatever environment runs the suite.
     * `ecommerce-verify-satellite` runs it in a child of a Testbench
     * process, which passes on the skeleton's `.env` (database cache,
     * session, and queue) — tables the in-memory test database doesn't
     * have.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return void
     */
    protected function defineEnvironment( $app ): void
    {
        parent::defineEnvironment( $app );

        $app['config']->set( 'cache.default', 'array' );
        $app['config']->set( 'session.driver', 'array' );
        $app['config']->set( 'queue.default', 'sync' );
    }

    /**
     * Boots the engine and this package as the rest of the suite does.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  Test application.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders( $app ): array
    {
        return TestCase::packageProviders();
    }

    /**
     * A PayPal webhook request, signed or not.
     *
     * @since 1.0.0
     *
     * @param  bool  $signed  Whether to send the signature headers.
     *
     * @return Request
     */
    protected static function webhookRequest( bool $signed ): Request
    {
        $now  = CarbonImmutable::now()->utc()->format( 'Y-m-d\TH:i:s\Z' );
        $body = (string) json_encode( [
            'id'            => 'WH-CONTRACT-1',
            'event_version' => '1.0',
            'create_time'   => $now,
            'event_type'    => 'CHECKOUT.ORDER.APPROVED',
            'resource'      => [ 'id' => Orders::ORDER_ID, 'status' => 'APPROVED' ],
        ], JSON_THROW_ON_ERROR );

        $headers = [
            'CONTENT_TYPE'                  => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID'   => 'b2384410-f8d2-11e8-8f6a-0123456789ab',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => $now,
            'HTTP_PAYPAL_CERT_URL'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-test',
            'HTTP_PAYPAL_AUTH_ALGO'         => 'SHA256withRSA',
        ];

        if ( $signed ) {
            $headers['HTTP_PAYPAL_TRANSMISSION_SIG'] = base64_encode( 'signature' );
        }

        return Request::create( '/ecommerce/webhooks/paypal', 'POST', [], [], [], $headers, $body );
    }
}
