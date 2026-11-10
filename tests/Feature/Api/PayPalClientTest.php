<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalAuthorization;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalRefund;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Api\PayPalCredentials;
use ArtisanPackUI\EcommercePayPal\Api\PayPalTokenProvider;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Support\PayPalEnvironment;
use ArtisanPackUI\EcommercePayPal\Support\PayPalRedactor;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'       => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret'   => 'client-secret',
        'artisanpack.ecommerce-paypal.mode'            => 'sandbox',
        'artisanpack.ecommerce-paypal.http.retries'    => 2,
        'artisanpack.ecommerce-paypal.logging.channel' => 'paypal-test',
        'logging.channels.paypal-test'                 => [ 'driver' => 'monolog', 'handler' => TestHandler::class ],
    ] );

    Http::preventStrayRequests();
    Sleep::fake();

    $this->paypal = PayPalFake::fake();
    $this->client = app( PayPalClient::class );
} );

/**
 * The records logged to the test channel.
 *
 * @return array<int, LogRecord>
 */
function paypalLogRecords(): array
{
    return Log::channel( 'paypal-test' )->getLogger()->getHandlers()[0]->getRecords();
}

describe( 'environment', function (): void {
    it( 'resolves hosts from the mode', function (): void {
        $sandbox = PayPalEnvironment::sandbox();
        $live    = PayPalEnvironment::live();

        expect( $sandbox->apiBase() )->toBe( 'https://api-m.sandbox.paypal.com' )
            ->and( $live->apiBase() )->toBe( 'https://api-m.paypal.com' )
            ->and( $sandbox->webBase() )->toBe( 'https://www.sandbox.paypal.com' )
            ->and( $live->webBase() )->toBe( 'https://www.paypal.com' )
            ->and( $live->certificateHost() )->toBe( 'api.paypal.com' )
            ->and( $sandbox->certificateHost() )->toBe( 'api.sandbox.paypal.com' )
            ->and( $live->transactionUrl( 'CAP 1' ) )->toBe( 'https://www.paypal.com/activity/payment/CAP%201' )
            ->and( $sandbox->disputeUrl( 'PP-D-1' ) )->toBe( 'https://www.sandbox.paypal.com/resolutioncenter/view/PP-D-1' )
            ->and( $live->developerDashboardUrl() )->toBe( 'https://developer.paypal.com/dashboard/applications/live' );
    } );

    it( 'reads the mode from config and falls back to sandbox', function ( mixed $mode, string $expected ): void {
        config( [ 'artisanpack.ecommerce-paypal.mode' => $mode ] );

        expect( PayPalEnvironment::fromConfig()->mode )->toBe( $expected );
    } )->with( [
        [ 'live', 'live' ],
        [ ' LIVE ', 'live' ],
        [ 'sandbox', 'sandbox' ],
        [ 'production', 'sandbox' ],
        [ null, 'sandbox' ],
    ] );

    it( 'refuses an unknown mode when built directly', function (): void {
        new PayPalEnvironment( 'staging' );
    } )->throws( InvalidArgumentException::class );

    it( 'calls the live host in live mode', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.mode' => 'live' ] );

        $this->client->orders()->get( 'ORDER-1' );

        $this->paypal->assertSent( 'orders.get', fn ( Request $request ): bool => str_starts_with( $request->url(), 'https://api-m.paypal.com/' ) );
    } );

    it( 'never serializes the secret', function (): void {
        $credentials = new PayPalCredentials( 'client-id', 'super-secret-value', PayPalEnvironment::live() );
        $serialized  = serialize( $credentials );
        $restored    = unserialize( $serialized );

        expect( $serialized )->not->toContain( 'super-secret-value' )
            ->and( $restored->clientId )->toBe( 'client-id' )
            ->and( $restored->environment->mode )->toBe( PayPalEnvironment::LIVE )
            ->and( $restored->isComplete() )->toBeFalse();
    } );

    it( 'never exposes the secret in debug output', function (): void {
        $credentials = PayPalCredentials::fromConfig();

        expect( json_encode( $credentials->__debugInfo() ) )->not->toContain( 'client-secret' )
            ->and( json_encode( $credentials ) )->not->toContain( 'client-secret' )
            ->and( $credentials->secret() )->toBe( 'client-secret' );
    } );
} );

describe( 'tokens', function (): void {
    it( 'fetches a token with the client credentials and caches it', function (): void {
        $this->client->orders()->get( 'A' );
        $this->client->orders()->get( 'B' );

        $this->paypal->assertSentCount( 'oauth.token', 1 );
        $this->paypal->assertSent( 'oauth.token', fn ( Request $request ): bool => $request->hasHeader( 'Authorization', 'Basic ' . base64_encode( 'client-id:client-secret' ) )
            && 'client_credentials' === $request['grant_type'] );
        $this->paypal->assertSent( 'orders.get', fn ( Request $request ): bool => $request->hasHeader( 'Authorization', 'Bearer A21AAFakeAccessTokenForTests' ) );
    } );

    it( 'caches per mode and client id until 60 seconds before expiry', function (): void {
        $this->paypal->respond( 'oauth.token', [ 'access_token' => 'short', 'expires_in' => 120 ] );

        app( PayPalTokenProvider::class )->token( PayPalCredentials::fromConfig() );

        $key = PayPalTokenProvider::CACHE_PREFIX . PayPalCredentials::fromConfig()->cacheKey();

        expect( Cache::get( $key ) )->toBe( 'short' )
            ->and( $key )->not->toContain( 'client-secret' );

        $this->travel( 61 )->seconds();

        expect( Cache::get( $key ) )->toBeNull();
    } );

    it( 'keeps separate tokens per mode', function (): void {
        $this->client->orders()->get( 'A' );

        config( [ 'artisanpack.ecommerce-paypal.mode' => 'live' ] );

        $this->client->orders()->get( 'A' );

        $this->paypal->assertSentCount( 'oauth.token', 2 );
    } );

    it( 'does not cache a token that is about to expire', function (): void {
        $this->paypal->respond( 'oauth.token', [ 'access_token' => 'stale', 'expires_in' => 30 ] );

        app( PayPalTokenProvider::class )->token( PayPalCredentials::fromConfig() );

        expect( Cache::get( PayPalTokenProvider::CACHE_PREFIX . PayPalCredentials::fromConfig()->cacheKey() ) )->toBeNull();
    } );

    it( 'refreshes the token once on a 401 and replays the call', function (): void {
        $this->paypal->error( 'orders.get', 'unauthorized' );

        $order = $this->client->orders()->get( 'A' );

        expect( $order->status() )->toBe( 'APPROVED' );
        $this->paypal->assertSentCount( 'oauth.token', 2 );
        $this->paypal->assertSentCount( 'orders.get', 2 );
    } );

    it( 'logs the rejected token with its debug id before refreshing', function (): void {
        $this->paypal->respond( 'orders.get', [ 'name' => 'AUTHENTICATION_FAILURE' ], 401, [ 'PayPal-Debug-Id' => 'dbg-401' ] );

        $this->client->orders()->get( 'A' );

        $records = paypalLogRecords();

        expect( $records )->toHaveCount( 1 )
            ->and( $records[0]->message )->toBe( 'PayPal rejected the access token; fetching a new one.' )
            ->and( $records[0]->context['status'] )->toBe( 401 )
            ->and( $records[0]->context['debug_id'] )->toBe( 'dbg-401' );
    } );

    it( 'gives up after a second 401', function (): void {
        $this->paypal->error( 'orders.get', 'unauthorized', times: 2 );

        expect( fn () => $this->client->orders()->get( 'A' ) )->toThrow( PayPalApiException::class );

        $this->paypal->assertSentCount( 'orders.get', 2 );
    } );

    it( 'throws an authentication failure for refused credentials', function (): void {
        $this->paypal->error( 'oauth.token', 'invalid-client' );

        try {
            app( PayPalTokenProvider::class )->fetch( PayPalCredentials::fromConfig() );
            $this->fail( 'Expected an exception.' );
        } catch ( PayPalApiException $exception ) {
            expect( $exception->isAuthenticationFailure() )->toBeTrue()
                ->and( $exception->name )->toBe( 'invalid_client' )
                ->and( $exception->getMessage() )->toBe( 'Client Authentication failed' );
        }
    } );

    it( 'verifies credentials', function (): void {
        $provider = app( PayPalTokenProvider::class );

        expect( $provider->verify( PayPalCredentials::fromConfig() ) )->toBeTrue();

        $this->paypal->error( 'oauth.token', 'invalid-client' );

        expect( $provider->verify( PayPalCredentials::fromConfig() ) )->toBeFalse()
            ->and( $provider->verify( new PayPalCredentials( '', '', PayPalEnvironment::sandbox() ) ) )->toBeFalse();

        $this->paypal->error( 'oauth.token', 'internal-server-error' );

        expect( fn () => $provider->verify( PayPalCredentials::fromConfig() ) )->toThrow( PayPalApiException::class );
    } );

    it( 'only treats an authentication failure as refused credentials', function (): void {
        $this->paypal->respond( 'oauth.token', [ 'error' => 'unsupported_grant_type', 'error_description' => 'Grant type is not supported.' ], 400 );

        $provider = app( PayPalTokenProvider::class );

        expect( fn () => $provider->verify( PayPalCredentials::fromConfig() ) )->toThrow( PayPalApiException::class );
    } );

    it( 'retries a failed id token request', function (): void {
        $this->paypal->respond( 'oauth.token', PayPalFake::fixture( 'errors/internal-server-error' ), 503 );
        $this->paypal->respond( 'oauth.token', [ 'access_token' => 'A21', 'token_type' => 'Bearer', 'expires_in' => 3600, 'id_token' => 'id-token-value' ] );

        expect( $this->client->idToken() )->toBe( 'id-token-value' );

        Sleep::assertSleptTimes( 1 );
    } );

    it( 'mints an id token, optionally for a customer', function (): void {
        expect( $this->client->idToken( 'customer_1' ) )->toStartWith( 'eyJ' );

        $this->paypal->assertSent( 'oauth.token', fn ( Request $request ): bool => 'id_token' === $request['response_type'] && 'customer_1' === $request['target_customer_id'] );

        $this->client->idToken();

        $this->paypal->assertSent( 'oauth.token', fn ( Request $request ): bool => 'id_token' === $request['response_type'] && ! isset( $request['target_customer_id'] ) );
    } );
} );

describe( 'idempotency and retries', function (): void {
    it( 'requires a request id on mutating calls', function ( string $method ): void {
        $this->client->{$method}( '/v2/checkout/orders', [], '' );
    } )->with( [ 'post', 'patch' ] )->throws( InvalidArgumentException::class );

    it( 'sends the request id', function (): void {
        $this->client->orders()->capture( 'ORDER-1', 'capture-42' );

        $this->paypal->assertSent( 'orders.capture', fn ( Request $request ): bool => $request->hasHeader( 'PayPal-Request-Id', 'capture-42' )
            && $request->hasHeader( 'Prefer', 'return=representation' ) );
    } );

    it( 'retries timeouts, 429s, and 5xx with the same request id', function ( Closure $fail ): void {
        $fail( $this->paypal );

        $order = $this->client->orders()->capture( 'ORDER-1', 'capture-42' );

        expect( $order->status() )->toBe( 'COMPLETED' );

        $sent = $this->paypal->sent( 'orders.capture' );

        expect( $sent )->toHaveCount( 3 )
            ->and( array_unique( array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $sent ) ) )->toBe( [ 'capture-42' ] );

        Sleep::assertSleptTimes( 2 );
    } )->with( [
        'timeouts'   => [ fn ( PayPalFake $paypal ) => $paypal->timeout( 'orders.capture', 2 ) ],
        '429s'       => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'rate-limit', times: 2 ) ],
        '5xx'        => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'internal-server-error', times: 2 ) ],
    ] );

    it( 'stops after the configured number of retries', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.http.retries' => 1 ] );

        $this->paypal->error( 'orders.capture', 'internal-server-error', times: 3 );

        expect( fn () => $this->client->orders()->capture( 'ORDER-1', 'capture-42' ) )
            ->toThrow( fn ( PayPalApiException $exception ) => expect( $exception->status )->toBe( 500 )->and( $exception->isRetryable() )->toBeTrue() );

        $this->paypal->assertSentCount( 'orders.capture', 2 );
    } );

    it( 'throws a retryable connection failure after the last timeout', function (): void {
        $this->paypal->timeout( 'orders.get', 3 );

        expect( fn () => $this->client->orders()->get( 'A' ) )
            ->toThrow( fn ( PayPalApiException $exception ) => expect( $exception->status )->toBe( 0 )->and( $exception->isRetryable() )->toBeTrue() );
    } );

    it( 'masks a saved-payment token id in a connection failure', function ( string $path ): void {
        $exception = PayPalApiException::connectionFailed( new RuntimeException( 'cURL error 28: Operation timed out for https://api-m.sandbox.paypal.com' . $path . ' after 15000 ms' ) );

        expect( $exception->getMessage() )
            ->not->toContain( 'tok-secret' )
            ->toContain( '[redacted]' )
            ->toContain( 'after 15000 ms' );
    } )->with( [
        'payment token' => [ '/v3/vault/payment-tokens/tok-secret' ],
        'setup token'   => [ '/v3/vault/setup-tokens/tok-secret?x=1' ],
    ] );

    it( 'never retries other 4xx', function ( string $error ): void {
        $this->paypal->error( 'orders.capture', $error );

        expect( fn () => $this->client->orders()->capture( 'ORDER-1', 'capture-42' ) )->toThrow( PayPalApiException::class );

        $this->paypal->assertSentCount( 'orders.capture', 1 );
        Sleep::assertNeverSlept();
    } )->with( [ 'instrument-declined', 'invalid-request', 'resource-not-found' ] );

    it( 'honors Retry-After', function (): void {
        $this->paypal->respond( 'orders.get', PayPalFake::fixture( 'errors/rate-limit' ), 429, [ 'Retry-After' => '3' ] );

        $this->client->orders()->get( 'A' );

        Sleep::assertSlept( fn ( $duration ): bool => $duration->totalMilliseconds >= 3000, 1 );
    } );

    it( 'honors a Retry-After date', function (): void {
        $this->freezeSecond();

        $this->paypal->respond( 'orders.get', PayPalFake::fixture( 'errors/rate-limit' ), 429, [ 'Retry-After' => now()->addSeconds( 5 )->toRfc7231String() ] );

        $this->client->orders()->get( 'A' );

        Sleep::assertSlept( fn ( $duration ): bool => $duration->totalMilliseconds >= 5000, 1 );
    } );

    it( 'gives up instead of waiting out a long Retry-After', function (): void {
        $this->paypal->respond( 'orders.get', PayPalFake::fixture( 'errors/rate-limit' ), 429, [ 'Retry-After' => '120' ] );

        try {
            $this->client->orders()->get( 'A' );
            $this->fail( 'Expected an exception.' );
        } catch ( PayPalApiException $exception ) {
            expect( $exception->status )->toBe( 429 )
                ->and( $exception->retryAfter )->toBe( '120' );
        }

        $this->paypal->assertSentCount( 'orders.get', 1 );
        Sleep::assertNeverSlept();
    } );

    it( 'backs off exponentially with jitter without Retry-After', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.http.retries' => 3 ] );

        $this->paypal->error( 'orders.get', 'internal-server-error', times: 3 );

        $this->client->orders()->get( 'A' );

        $slept = [];

        Sleep::assertSlept( function ( $duration ) use ( &$slept ): bool {
            $slept[] = $duration->totalMilliseconds;

            return true;
        }, 3 );

        $base = PayPalClient::BACKOFF_BASE_MS;

        expect( $slept[0] )->toBeGreaterThanOrEqual( $base / 2 )->toBeLessThanOrEqual( $base )
            ->and( $slept[2] )->toBeGreaterThanOrEqual( $base * 2 )->toBeLessThanOrEqual( $base * 4 );
    } );

    it( 'sends an empty body as a JSON object', function (): void {
        $this->client->orders()->capture( 'ORDER-1', 'capture-42' );

        $this->paypal->assertSent( 'orders.capture', fn ( Request $request ): bool => '{}' === $request->body() );
    } );
} );

describe( 'errors', function (): void {
    it( 'carries the PayPal error details', function (): void {
        $this->paypal->error( 'orders.capture', 'instrument-declined' );

        try {
            $this->client->orders()->capture( 'ORDER-1', 'capture-42' );
            $this->fail( 'Expected an exception.' );
        } catch ( PayPalApiException $exception ) {
            expect( $exception->status )->toBe( 422 )
                ->and( $exception->name )->toBe( 'UNPROCESSABLE_ENTITY' )
                ->and( $exception->issue() )->toBe( 'INSTRUMENT_DECLINED' )
                ->and( $exception->hasIssue( 'INSTRUMENT_DECLINED' ) )->toBeTrue()
                ->and( $exception->hasIssue( 'OTHER' ) )->toBeFalse()
                ->and( $exception->debugId )->toBe( PayPalFake::DEBUG_ID )
                ->and( $exception->isRetryable() )->toBeFalse();
        }
    } );

    it( 'always logs the debug id, without bodies', function (): void {
        $this->paypal->error( 'orders.capture', 'instrument-declined' );

        try {
            $this->client->orders()->capture( 'ORDER-1', 'capture-42' );
        } catch ( PayPalApiException ) {
        }

        $records = paypalLogRecords();

        expect( $records )->toHaveCount( 1 )
            ->and( $records[0]->message )->toBe( 'PayPal API call failed.' )
            ->and( $records[0]->context['debug_id'] )->toBe( PayPalFake::DEBUG_ID )
            ->and( $records[0]->context['issue'] )->toBe( 'INSTRUMENT_DECLINED' )
            ->and( $records[0]->context )->not->toHaveKey( 'body' );
    } );

    it( 'logs redacted bodies only with debug logging', function (): void {
        $this->client->orders()->get( 'A' );

        expect( paypalLogRecords() )->toBeEmpty();

        config( [ 'artisanpack.ecommerce-paypal.logging.debug' => true ] );

        $this->paypal->respond( 'orders.create', [ 'id' => 'ORDER-1', 'status' => 'CREATED', 'payer' => [ 'email_address' => 'payer-response@example.com' ] ] );

        $this->client->orders()->create( [ 'payer' => [ 'email_address' => 'buyer@example.com' ] ], 'create-1' );

        $records = paypalLogRecords();
        $logged  = json_encode( array_map( fn ( LogRecord $record ): array => $record->context, $records ) );

        expect( $records )->toHaveCount( 2 )
            ->and( $records[0]->context['headers']['PayPal-Request-Id'] )->toBe( 'create-1' )
            ->and( $records[0]->context['body']['payer']['email_address'] )->toBe( PayPalRedactor::MASK )
            ->and( $records[1]->context['body']['payer']['email_address'] )->toBe( PayPalRedactor::MASK )
            ->and( $logged )->not->toContain( 'payer-response@example.com' )
            ->and( $logged )->not->toContain( 'buyer@example.com' )
            ->and( $logged )->not->toContain( 'A21AAFakeAccessTokenForTests' )
            ->and( $logged )->not->toContain( 'client-secret' );
    } );

    it( 'sends a raw body byte for byte, with the token and a redacted debug log', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.logging.debug' => true ] );
        $raw = '{"id":"evt","url":"https://example.com/a/b","price":"10 €","resource":{"payer":{"email_address":"buyer@example.com"},"empty":{}}}';

        $this->client->postRawReadOnly( '/v1/notifications/verify-webhook-signature', $raw );

        $this->paypal->assertSent( 'webhooks.verify_signature', fn ( Request $request ): bool => $raw === $request->body()
            && $request->hasHeader( 'Authorization', 'Bearer A21AAFakeAccessTokenForTests' )
            && $request->hasHeader( 'Content-Type', 'application/json' )
            && ! $request->hasHeader( 'PayPal-Request-Id' ) );

        $records = paypalLogRecords();

        expect( $records[0]->context['body']['resource']['payer']['email_address'] )->toBe( PayPalRedactor::MASK )
            ->and( json_encode( array_map( fn ( LogRecord $record ): array => $record->context, $records ) ) )->not->toContain( 'buyer@example.com' );
    } );

    it( 'retries a raw body after a token refresh', function (): void {
        $this->paypal->error( 'webhooks.verify_signature', 'unauthorized' );

        $this->client->postRawReadOnly( '/v1/notifications/verify-webhook-signature', '{"id":"evt"}' );

        expect( $this->paypal->sent( 'webhooks.verify_signature' ) )->toHaveCount( 2 )
            ->and( array_map( fn ( Request $request ): string => $request->body(), $this->paypal->sent( 'webhooks.verify_signature' ) ) )->toBe( [ '{"id":"evt"}', '{"id":"evt"}' ] );
    } );
} );

describe( 'typed endpoints', function (): void {
    it( 'parses orders', function (): void {
        $created = $this->client->orders()->create( [ 'intent' => 'CAPTURE' ], 'create-1' );

        expect( $created )->toBeInstanceOf( PayPalOrder::class )
            ->and( $created->id() )->toBe( '5O190127TN364715T' )
            ->and( $created->status() )->toBe( 'PAYER_ACTION_REQUIRED' )
            ->and( $created->payerActionUrl() )->toContain( 'checkoutnow' )
            ->and( $created->customId() )->toBe( '1' )
            ->and( $created->invoiceId() )->toBe( 'cart-1-1' );

        $approved = $this->client->orders()->get( '5O190127TN364715T' );

        expect( $approved->status() )->toBe( 'APPROVED' )
            ->and( $approved->intent() )->toBe( 'CAPTURE' )
            ->and( $approved->paymentSourceType() )->toBe( 'paypal' )
            ->and( $approved->payerEmail() )->toBe( 'buyer@example.com' )
            ->and( $approved->payerId() )->toBe( 'QYR5Z8XDVJNXQ' )
            ->and( $approved->amount() )->toBe( [ 'currency_code' => 'USD', 'value' => '100.00' ] )
            ->and( $approved->latestCapture() )->toBeNull();

        $captured = $this->client->orders()->capture( '5O190127TN364715T', 'capture-1' );

        expect( $captured->latestCapture()?->id() )->toBe( '3C679366HH908993F' )
            ->and( $captured->latestCapture()?->status() )->toBe( 'COMPLETED' )
            ->and( $captured->captures() )->toHaveCount( 1 );

        $authorized = $this->client->orders()->authorize( '5O190127TN364715T', 'authorize-1' );

        expect( $authorized->intent() )->toBe( 'AUTHORIZE' )
            ->and( $authorized->latestAuthorization()?->id() )->toBe( '0VF52814937998046' )
            ->and( $authorized->latestAuthorization()?->expiresAt()?->toDateString() )->toBe( '2026-12-08' )
            ->and( $authorized->liabilityShift() )->toBe( 'POSSIBLE' )
            ->and( $authorized->paymentSourceType() )->toBe( 'card' );

        $this->client->orders()->patch( '5O190127TN364715T', [ [ 'op' => 'replace', 'path' => '/purchase_units/@reference_id==\'default\'/amount', 'value' => [] ] ], 'patch-1' );

        $this->paypal->assertSent( 'orders.patch', fn ( Request $request ): bool => 'PATCH' === $request->method() && 'replace' === $request->data()[0]['op'] );
    } );

    it( 'parses payments', function (): void {
        $payments = $this->client->payments();

        $authorization = $payments->getAuthorization( '0VF52814937998046' );

        expect( $authorization )->toBeInstanceOf( PayPalAuthorization::class )
            ->and( $authorization->status() )->toBe( 'CREATED' )
            ->and( $authorization->orderId() )->toBe( '5O190127TN364715T' )
            ->and( $authorization->amount() )->toBe( [ 'currency_code' => 'USD', 'value' => '100.00' ] );

        $capture = $payments->captureAuthorization( '0VF52814937998046', 'capture-1', [ 'currency_code' => 'USD', 'value' => '100.00' ] );

        expect( $capture )->toBeInstanceOf( PayPalCapture::class )
            ->and( $capture->isFinalCapture() )->toBeTrue()
            ->and( $capture->orderId() )->toBe( '5O190127TN364715T' )
            ->and( $capture->authorizationId() )->toBe( '0VF52814937998046' );

        $this->paypal->assertSent( 'payments.authorizations.capture', fn ( Request $request ): bool => true === $request['final_capture'] && '100.00' === $request['amount']['value'] );

        expect( $payments->voidAuthorization( '0VF52814937998046', 'void-1' )?->status() )->toBe( 'VOIDED' )
            ->and( $payments->reauthorize( '0VF52814937998046', 'reauth-1' )->id() )->toBe( '8AA831015G517922L' )
            ->and( $payments->getCapture( '3C679366HH908993F' )->status() )->toBe( 'COMPLETED' );

        $this->paypal->respond( 'payments.captures.get', 'payments/capture-pending' );

        expect( $payments->getCapture( '3C679366HH908993F' )->pendingReason() )->toBe( 'PENDING_REVIEW' );

        $refund = $payments->refundCapture( '3C679366HH908993F', 'refund-1', [ 'currency_code' => 'USD', 'value' => '10.00' ], 'refund-1', str_repeat( 'x', 300 ), '1' );

        expect( $refund )->toBeInstanceOf( PayPalRefund::class )
            ->and( $refund->captureId() )->toBe( '3C679366HH908993F' )
            ->and( $refund->customId() )->toBe( '1' )
            ->and( $refund->invoiceId() )->toBe( 'refund-1' )
            ->and( $refund->amount() )->toBe( [ 'currency_code' => 'USD', 'value' => '10.00' ] );

        $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => 255 === mb_strlen( $request['note_to_payer'] ) );

        expect( $payments->getRefund( '1JU08902781691411' )->status() )->toBe( 'COMPLETED' );
    } );

    it( 'treats an empty void response as no body', function (): void {
        $this->paypal->respond( 'payments.authorizations.void', [], 204 );

        expect( $this->client->payments()->voidAuthorization( 'AUTH', 'void-1' ) )->toBeNull();
    } );

    it( 'parses the vault', function (): void {
        $vault = $this->client->vault();

        $list = $vault->listPaymentTokens( 'customer_4029352050', 1, 50 );

        expect( $list->tokens() )->toHaveCount( 2 )
            ->and( $list->totalItems() )->toBe( 2 )
            ->and( $list->totalPages() )->toBe( 1 )
            ->and( $list->tokens()[1]->sourceType() )->toBe( 'paypal' );

        $this->paypal->assertSent( 'vault.payment_tokens.list', fn ( Request $request ): bool => str_contains( $request->url(), 'page_size=5' ) && str_contains( $request->url(), 'total_required=true' ) && str_contains( $request->url(), 'customer_id=customer_4029352050' ) );

        $token = $vault->getPaymentToken( '8kk8451t' );

        expect( $token->customerId() )->toBe( 'customer_4029352050' )
            ->and( $token->sourceType() )->toBe( 'card' )
            ->and( $token->source()['last_digits'] )->toBe( '1111' );

        $vault->deletePaymentToken( '8kk8451t', 'delete-1' );
        $this->paypal->assertSent( 'vault.payment_tokens.delete', fn ( Request $request ): bool => $request->hasHeader( 'PayPal-Request-Id', 'delete-1' ) );

        $setup = $vault->createSetupToken( [ 'paypal' => [ 'usage_type' => 'MERCHANT' ] ], 'setup-1', 'customer_4029352050' );

        expect( $setup->status() )->toBe( 'PAYER_ACTION_REQUIRED' )
            ->and( $setup->approveUrl() )->toContain( 'approval_session_id' )
            ->and( $setup->customerId() )->toBe( 'customer_4029352050' )
            ->and( $vault->getSetupToken( '5C991763VB2781612' )->id() )->toBe( '5C991763VB2781612' );

        $this->paypal->assertSent( 'vault.setup_tokens.create', fn ( Request $request ): bool => 'customer_4029352050' === $request['customer']['id'] );
    } );

    it( 'parses webhooks', function (): void {
        $webhooks = $this->client->webhooks();

        $created = $webhooks->create( 'https://example.com/hook', [ 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED' ], 'hook-1' );

        expect( $created->id() )->toBe( '0EH40505U7160970P' )
            ->and( $created->eventTypes() )->toContain( 'PAYMENT.CAPTURE.COMPLETED' );

        $this->paypal->assertSent( 'webhooks.create', fn ( Request $request ): bool => [ [ 'name' => 'PAYMENT.CAPTURE.COMPLETED' ] ] === $request['event_types'] );

        expect( $webhooks->list() )->toHaveCount( 1 )
            ->and( $webhooks->get( '0EH40505U7160970P' )->url() )->toBe( 'https://example.com/ecommerce/webhooks/paypal' );

        $webhooks->update( '0EH40505U7160970P', [ 'PAYMENT.CAPTURE.REFUNDED' ], 'hook-2', 'https://example.com/new' );

        $this->paypal->assertSent( 'webhooks.update', fn ( Request $request ): bool => '/event_types' === $request->data()[0]['path'] && '/url' === $request->data()[1]['path'] );

        $transmission = [
            'transmission_id'   => 'id',
            'transmission_time' => '2026-11-09T12:00:00Z',
            'transmission_sig'  => 'sig',
            'cert_url'          => 'https://api.sandbox.paypal.com/cert.pem',
            'auth_algo'         => 'SHA256withRSA',
        ];

        expect( $webhooks->verifySignature( $transmission, 'WH-1', '{"id":"evt"}' ) )->toBeTrue();

        $this->paypal->assertSent( 'webhooks.verify_signature', fn ( Request $request ): bool => ! $request->hasHeader( 'PayPal-Request-Id' ) && 'WH-1' === $request['webhook_id'] );

        $this->paypal->respond( 'webhooks.verify_signature', 'webhooks/verify-signature-failure' );

        expect( $webhooks->verifySignature( $transmission, 'WH-1', '{"id":"evt"}' ) )->toBeFalse();

        $events = $webhooks->events( 'PAYMENT.CAPTURE.COMPLETED' );

        expect( $events->events() )->toHaveCount( 1 )
            ->and( $events->events()[0]->eventType() )->toBe( 'PAYMENT.CAPTURE.COMPLETED' )
            ->and( $events->events()[0]->resourceType() )->toBe( 'capture' )
            ->and( $events->events()[0]->resource()['id'] )->toBe( '3C679366HH908993F' )
            ->and( $events->events()[0]->createTime() )->not->toBeNull()
            ->and( $events->nextUrl() )->toBeNull();
    } );

    it( 'masks saved-payment token ids in debug logs', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.logging.debug' => true ] );

        $token = $this->client->vault()->getPaymentToken( 'TOKEN-SECRET-ID' );

        $logged = json_encode( array_map( fn ( LogRecord $record ): array => $record->context, paypalLogRecords() ), JSON_UNESCAPED_SLASHES );

        expect( $logged )->not->toContain( 'TOKEN-SECRET-ID' )
            ->and( $logged )->not->toContain( $token->id() )
            ->and( $logged )->toContain( '/v3/vault/payment-tokens/' . PayPalRedactor::MASK );
    } );

    it( 'rejects a webhook response without an id or URL', function ( string $endpoint, Closure $call ): void {
        $this->paypal->respond( $endpoint, [ 'unexpected' => true ] );

        expect( fn () => $call( $this->client ) )->toThrow( PayPalApiException::class, 'PayPal returned a webhook without an id or URL.' );
    } )->with( [
        'create' => [ 'webhooks.create', fn ( PayPalClient $client ) => $client->webhooks()->create( 'https://shop.test/hook', [ 'PAYMENT.CAPTURE.COMPLETED' ], 'wh-1' ) ],
        'get'    => [ 'webhooks.get', fn ( PayPalClient $client ) => $client->webhooks()->get( 'WH-1' ) ],
    ] );

    it( 'lets a newer fake take over PayPal responses', function (): void {
        $newer = PayPalFake::fake();
        $newer->respond( 'orders.get', [ 'id' => 'FROM-NEWER', 'status' => 'APPROVED' ] );

        expect( $this->client->orders()->get( 'A' )->id() )->toBe( 'FROM-NEWER' );

        $newer->assertSentCount( 'orders.get', 1 );
        $this->paypal->assertNotSent( 'orders.get' );
    } );

    it( 'answers unknown PayPal paths with a 404', function (): void {
        expect( fn () => $this->client->get( '/v9/nothing' ) )
            ->toThrow( fn ( PayPalApiException $exception) => expect( $exception->status )->toBe( 404 ) );
    } );
} );
