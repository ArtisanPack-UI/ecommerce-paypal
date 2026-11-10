<?php

/**
 * Fake PayPal API for tests.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Testing;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use WeakReference;

/**
 * Stands in for PayPal's REST API through `Http::fake()`.
 *
 * Every endpoint the package calls answers with a recorded fixture (shapes
 * from PayPal's published OpenAPI examples) until a test queues something
 * else:
 *
 * ```php
 * $paypal = PayPalFake::fake();
 *
 * $paypal->respond( 'orders.capture', 'orders/captured' );          // a fixture
 * $paypal->respond( 'orders.get', [ 'id' => 'X', 'status' => 'VOIDED' ] ); // a body
 * $paypal->error( 'orders.capture', 'instrument-declined' );        // a 422
 * $paypal->timeout( 'payments.captures.refund' );                     // no response
 *
 * $paypal->assertSent( 'orders.capture', fn ( Request $request ): bool => … );
 * ```
 *
 * Queued responses are used once each, in order. Requests to hosts other
 * than PayPal's are not intercepted; requests to unknown PayPal paths get a
 * `404`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalFake
{
    /**
     * Debug id sent with every faked error.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DEBUG_ID = 'fake-debug-id';

    /**
     * Endpoints: `key => [ method, path pattern, default fixture, default status ]`.
     *
     * A null fixture answers with an empty body.
     *
     * @since 1.0.0
     *
     * @var array<string, array{0: string, 1: string, 2: string|null, 3: int}>
     */
    public const ENDPOINTS = [
        'oauth.token'                         => [ 'POST', '#^/v1/oauth2/token$#', 'oauth/token', 200 ],
        'orders.create'                       => [ 'POST', '#^/v2/checkout/orders$#', 'orders/created', 200 ],
        'orders.get'                          => [ 'GET', '#^/v2/checkout/orders/[^/]+$#', 'orders/approved', 200 ],
        'orders.patch'                        => [ 'PATCH', '#^/v2/checkout/orders/[^/]+$#', null, 204 ],
        'orders.authorize'                    => [ 'POST', '#^/v2/checkout/orders/[^/]+/authorize$#', 'orders/authorized', 201 ],
        'orders.capture'                      => [ 'POST', '#^/v2/checkout/orders/[^/]+/capture$#', 'orders/captured', 201 ],
        'payments.authorizations.get'         => [ 'GET', '#^/v2/payments/authorizations/[^/]+$#', 'payments/authorization', 200 ],
        'payments.authorizations.capture'     => [ 'POST', '#^/v2/payments/authorizations/[^/]+/capture$#', 'payments/capture', 201 ],
        'payments.authorizations.void'        => [ 'POST', '#^/v2/payments/authorizations/[^/]+/void$#', 'payments/authorization-voided', 200 ],
        'payments.authorizations.reauthorize' => [ 'POST', '#^/v2/payments/authorizations/[^/]+/reauthorize$#', 'payments/authorization-reauthorized', 201 ],
        'payments.captures.get'               => [ 'GET', '#^/v2/payments/captures/[^/]+$#', 'payments/capture', 200 ],
        'payments.captures.refund'            => [ 'POST', '#^/v2/payments/captures/[^/]+/refund$#', 'payments/refund', 201 ],
        'payments.refunds.get'                => [ 'GET', '#^/v2/payments/refunds/[^/]+$#', 'payments/refund', 200 ],
        'disputes.get'                        => [ 'GET', '#^/v1/customer/disputes/[^/]+$#', 'disputes/dispute', 200 ],
        'vault.payment_tokens.list'           => [ 'GET', '#^/v3/vault/payment-tokens$#', 'vault/payment-tokens', 200 ],
        'vault.payment_tokens.get'            => [ 'GET', '#^/v3/vault/payment-tokens/[^/]+$#', 'vault/payment-token', 200 ],
        'vault.payment_tokens.delete'         => [ 'DELETE', '#^/v3/vault/payment-tokens/[^/]+$#', null, 204 ],
        'vault.setup_tokens.create'           => [ 'POST', '#^/v3/vault/setup-tokens$#', 'vault/setup-token', 200 ],
        'vault.setup_tokens.get'              => [ 'GET', '#^/v3/vault/setup-tokens/[^/]+$#', 'vault/setup-token', 200 ],
        'webhooks.create'                     => [ 'POST', '#^/v1/notifications/webhooks$#', 'webhooks/webhook', 201 ],
        'webhooks.list'                       => [ 'GET', '#^/v1/notifications/webhooks$#', 'webhooks/list', 200 ],
        'webhooks.get'                        => [ 'GET', '#^/v1/notifications/webhooks/[^/]+$#', 'webhooks/webhook', 200 ],
        'webhooks.update'                     => [ 'PATCH', '#^/v1/notifications/webhooks/[^/]+$#', 'webhooks/webhook', 200 ],
        'webhooks.verify_signature'           => [ 'POST', '#^/v1/notifications/verify-webhook-signature$#', 'webhooks/verify-signature', 200 ],
        'webhooks.events'                     => [ 'GET', '#^/v1/notifications/webhooks-events$#', 'webhooks/events', 200 ],
    ];

    /**
     * Error fixtures and their HTTP status.
     *
     * @since 1.0.0
     *
     * @var array<string, int>
     */
    public const ERRORS = [
        'invalid-client'                 => 401,
        'unauthorized'                   => 401,
        'invalid-request'                => 400,
        'resource-not-found'             => 404,
        'instrument-declined'            => 422,
        'order-already-captured'         => 422,
        'refund-amount-exceeded'         => 422,
        'capture-fully-refunded'         => 422,
        'authorization-already-captured' => 422,
        'webhook-url-already-exists'     => 400,
        'rate-limit'                     => 429,
        'internal-server-error'          => 500,
    ];

    /**
     * The fake that answers PayPal requests: the one created last.
     *
     * @since 1.0.0
     *
     * @var self|null
     */
    private static ?self $active = null;

    /**
     * The HTTP factory the dispatching stub is registered on.
     *
     * @since 1.0.0
     *
     * @var WeakReference<object>|null
     */
    private static ?WeakReference $factory = null;

    /**
     * Queued responses by endpoint key.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, Closure(Request): PromiseInterface>>
     */
    private array $queued = [];

    /**
     * Every PayPal request handled, including ones that timed out.
     *
     * @since 1.0.0
     *
     * @var array<int, array{0: string, 1: Request}>
     */
    private array $requests = [];

    /**
     * Starts faking PayPal and returns the fake.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function fake(): self
    {
        $fake    = new self();
        $factory = Http::getFacadeRoot();

        self::$active = $fake;

        // One stub per HTTP factory, dispatching to the newest fake: Laravel
        // runs stubs in registration order, so a second `Http::fake()` would
        // never be reached, and clearing the stubs would drop unrelated ones.
        if ( self::$factory?->get() !== $factory ) {
            self::$factory = WeakReference::create( $factory );

            Http::fake( static fn ( Request $request ): ?PromiseInterface => self::$active?->handle( $request ) );
        }

        return $fake;
    }

    /**
     * Decodes a fixture (`orders/captured`, `errors/instrument-declined`).
     *
     * @since 1.0.0
     *
     * @param  string  $name  Fixture path without `.json`.
     *
     * @throws InvalidArgumentException When the fixture doesn't exist.
     *
     * @return array<string, mixed>
     */
    public static function fixture( string $name ): array
    {
        $path = __DIR__ . '/fixtures/' . $name . '.json';

        if ( ! is_file( $path ) ) {
            throw new InvalidArgumentException( sprintf( 'Unknown PayPal fixture "%s".', $name ) );
        }

        return (array) json_decode( (string) file_get_contents( $path ), true, 512, JSON_THROW_ON_ERROR );
    }

    /**
     * Queues a response for the next call to `$endpoint`.
     *
     * @since 1.0.0
     *
     * @param  string                       $endpoint  Key from {@see self::ENDPOINTS}.
     * @param  array<string, mixed>|string  $body      Fixture name or body.
     * @param  int|null                     $status    Status; defaults to the endpoint's.
     * @param  array<string, string>        $headers   Response headers.
     *
     * @return self
     */
    public function respond( string $endpoint, string|array $body, ?int $status = null, array $headers = [] ): self
    {
        $definition = self::definition( $endpoint );
        $body       = is_string( $body ) ? self::fixture( $body ) : $body;

        return $this->queue( $endpoint, static fn (): PromiseInterface => Http::response( $body, $status ?? $definition[3], $headers ) );
    }

    /**
     * Queues an error fixture for the next call to `$endpoint`.
     *
     * @since 1.0.0
     *
     * @param  string    $endpoint  Key from {@see self::ENDPOINTS}.
     * @param  string    $error     Key from {@see self::ERRORS}.
     * @param  int|null  $status    Status; defaults to the error's.
     * @param  int       $times     How many calls fail.
     *
     * @return self
     */
    public function error( string $endpoint, string $error, ?int $status = null, int $times = 1 ): self
    {
        self::definition( $endpoint );

        if ( ! isset( self::ERRORS[ $error ] ) ) {
            throw new InvalidArgumentException( sprintf( 'Unknown PayPal error fixture "%s".', $error ) );
        }

        $body = self::fixture( 'errors/' . $error );

        for ( $i = 0; $i < $times; $i++ ) {
            $this->queue( $endpoint, static fn (): PromiseInterface => Http::response( $body, $status ?? self::ERRORS[ $error ], [ 'PayPal-Debug-Id' => self::DEBUG_ID ] ) );
        }

        return $this;
    }

    /**
     * Makes the next `$times` calls to `$endpoint` time out.
     *
     * @since 1.0.0
     *
     * @param  string  $endpoint  Key from {@see self::ENDPOINTS}.
     * @param  int     $times     How many calls time out.
     *
     * @return self
     */
    public function timeout( string $endpoint, int $times = 1 ): self
    {
        self::definition( $endpoint );

        for ( $i = 0; $i < $times; $i++ ) {
            $this->queue( $endpoint, static function (): PromiseInterface {
                throw new ConnectionException( 'cURL error 28: Operation timed out' );
            } );
        }

        return $this;
    }

    /**
     * The requests sent to `$endpoint`, oldest first.
     *
     * @since 1.0.0
     *
     * @param  string  $endpoint  Key from {@see self::ENDPOINTS}.
     *
     * @return array<int, Request>
     */
    public function sent( string $endpoint ): array
    {
        self::definition( $endpoint );

        $sent = [];

        foreach ( $this->requests as [ $key, $request ] ) {
            if ( $key === $endpoint ) {
                $sent[] = $request;
            }
        }

        return $sent;
    }

    /**
     * Asserts `$endpoint` was called (and that a call passes `$callback`).
     *
     * @since 1.0.0
     *
     * @param  string                       $endpoint  Key from {@see self::ENDPOINTS}.
     * @param  Closure(Request): bool|null  $callback  Optional check.
     *
     * @return void
     */
    public function assertSent( string $endpoint, ?Closure $callback = null ): void
    {
        $matching = array_filter( $this->sent( $endpoint ), static fn ( Request $request ): bool => null === $callback || true === $callback( $request ) );

        PHPUnit::assertNotEmpty( $matching, sprintf( 'No matching PayPal request was sent to [%s].', $endpoint ) );
    }

    /**
     * Asserts `$endpoint` was called exactly `$times` times.
     *
     * @since 1.0.0
     *
     * @param  string  $endpoint  Key from {@see self::ENDPOINTS}.
     * @param  int     $times     Expected calls.
     *
     * @return void
     */
    public function assertSentCount( string $endpoint, int $times ): void
    {
        PHPUnit::assertCount( $times, $this->sent( $endpoint ), sprintf( 'PayPal [%s] was not called the expected number of times.', $endpoint ) );
    }

    /**
     * Asserts `$endpoint` was never called.
     *
     * @since 1.0.0
     *
     * @param  string  $endpoint  Key from {@see self::ENDPOINTS}.
     *
     * @return void
     */
    public function assertNotSent( string $endpoint ): void
    {
        $this->assertSentCount( $endpoint, 0 );
    }

    /**
     * The endpoint key a request is for, or null when it isn't a PayPal API
     * request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return string|null
     */
    public static function match( Request $request ): ?string
    {
        $host = (string) parse_url( $request->url(), PHP_URL_HOST );

        if ( ! in_array( $host, [ 'api-m.paypal.com', 'api-m.sandbox.paypal.com' ], true ) ) {
            return null;
        }

        $path   = (string) parse_url( $request->url(), PHP_URL_PATH );
        $method = strtoupper( $request->method() );

        foreach ( self::ENDPOINTS as $key => [ $endpointMethod, $pattern ] ) {
            if ( $method === $endpointMethod && 1 === preg_match( $pattern, $path ) ) {
                return $key;
            }
        }

        return 'unknown';
    }

    /**
     * Answers a faked request.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return PromiseInterface|null Null lets non-PayPal requests through to other fakes.
     */
    public function handle( Request $request ): ?PromiseInterface
    {
        $endpoint = self::match( $request );

        if ( null === $endpoint ) {
            return null;
        }

        $this->requests[] = [ $endpoint, $request ];

        if ( 'unknown' === $endpoint ) {
            return Http::response( self::fixture( 'errors/resource-not-found' ), 404, [ 'PayPal-Debug-Id' => self::DEBUG_ID ] );
        }

        if ( [] !== ( $this->queued[ $endpoint ] ?? [] ) ) {
            $responder = array_shift( $this->queued[ $endpoint ] );

            return $responder( $request );
        }

        [ , , $fixture, $status ] = self::ENDPOINTS[ $endpoint ];

        if ( 'oauth.token' === $endpoint && str_contains( $request->body(), 'response_type=id_token' ) ) {
            $fixture = 'oauth/id-token';
        }

        $body = null === $fixture ? '' : self::fixture( $fixture );

        // Like PayPal, a created Order carries the cart id it was created
        // with, so the gateway's check that the Order is the cart's holds.
        if ( 'orders.create' === $endpoint && is_array( $body ) && is_string( $request['purchase_units'][0]['custom_id'] ?? null ) ) {
            $body['purchase_units'][0]['custom_id'] = $request['purchase_units'][0]['custom_id'];
        }

        return Http::response( $body, $status );
    }

    /**
     * Appends a responder to an endpoint's queue.
     *
     * @since 1.0.0
     *
     * @param  string                              $endpoint   Endpoint key.
     * @param  Closure(Request): PromiseInterface  $responder  Responder.
     *
     * @return self
     */
    private function queue( string $endpoint, Closure $responder ): self
    {
        $this->queued[ $endpoint ][] = $responder;

        return $this;
    }

    /**
     * An endpoint's definition.
     *
     * @since 1.0.0
     *
     * @param  string  $endpoint  Endpoint key.
     *
     * @throws RuntimeException When the key is unknown.
     *
     * @return array{0: string, 1: string, 2: string|null, 3: int}
     */
    private static function definition( string $endpoint ): array
    {
        if ( ! isset( self::ENDPOINTS[ $endpoint ] ) ) {
            throw new RuntimeException( sprintf( 'Unknown PayPal endpoint "%s".', $endpoint ) );
        }

        return self::ENDPOINTS[ $endpoint ];
    }
}
