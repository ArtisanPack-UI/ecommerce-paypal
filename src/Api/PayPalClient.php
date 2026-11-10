<?php

/**
 * PayPal REST API client.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api;

use ArtisanPackUI\EcommercePayPal\Api\Resources\DisputesApi;
use ArtisanPackUI\EcommercePayPal\Api\Resources\OrdersApi;
use ArtisanPackUI\EcommercePayPal\Api\Resources\PaymentsApi;
use ArtisanPackUI\EcommercePayPal\Api\Resources\VaultApi;
use ArtisanPackUI\EcommercePayPal\Api\Resources\WebhooksApi;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Support\PayPalEnvironment;
use ArtisanPackUI\EcommercePayPal\Support\PayPalRedactor;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * The one way the package talks to PayPal (spec §2.2 D2, §11).
 *
 * Built on Laravel's HTTP client, so `Http::fake()` (and {@see \ArtisanPackUI\EcommercePayPal\Testing\PayPalFake})
 * intercept every call. For each request it:
 *
 * - authenticates with a cached OAuth2 token, fetching a new one once on a
 *   `401`;
 * - sends a `PayPal-Request-Id` on every mutating call (required), so PayPal
 *   returns the original result for a repeated request instead of acting
 *   twice;
 * - retries timeouts, `429`s, and `5xx`s up to `http.retries` times with
 *   exponential backoff and jitter, always with the same request id. Other
 *   `4xx`s are never retried;
 * - applies `http.timeout` / `http.connect_timeout`, with TLS verification
 *   always on;
 * - throws {@see PayPalApiException} on failure and always logs PayPal's
 *   debug id. Bodies are logged only with `logging.debug`, redacted by
 *   {@see PayPalRedactor}.
 *
 * Typed endpoints live on {@see self::orders()}, {@see self::payments()},
 * {@see self::vault()}, and {@see self::webhooks()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalClient
{
    /**
     * First retry delay in milliseconds (doubled on each further retry).
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const BACKOFF_BASE_MS = 250;

    /**
     * Longest retry delay in milliseconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const BACKOFF_MAX_MS = 8000;

    /**
     * Methods that change state at PayPal.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const MUTATING_METHODS = [ 'POST', 'PUT', 'PATCH', 'DELETE' ];

    /**
     * Creates the client.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory              $http         HTTP client factory.
     * @param  PayPalTokenProvider      $tokens       Access tokens.
     * @param  PayPalCredentials|null   $credentials  Fixed credentials; null reads config on every call, so a settings change applies straight away.
     */
    public function __construct(
        protected HttpFactory $http,
        protected PayPalTokenProvider $tokens,
        protected ?PayPalCredentials $credentials = null,
    ) {
    }

    /**
     * A copy of this client that uses `$credentials`.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @return static
     */
    public function withCredentials( PayPalCredentials $credentials ): static
    {
        $client              = clone $this;
        $client->credentials = $credentials;

        return $client;
    }

    /**
     * The credentials in use.
     *
     * @since 1.0.0
     *
     * @return PayPalCredentials
     */
    public function credentials(): PayPalCredentials
    {
        return $this->credentials ?? PayPalCredentials::fromConfig();
    }

    /**
     * The environment in use.
     *
     * @since 1.0.0
     *
     * @return PayPalEnvironment
     */
    public function environment(): PayPalEnvironment
    {
        return $this->credentials()->environment;
    }

    /**
     * The token provider.
     *
     * @since 1.0.0
     *
     * @return PayPalTokenProvider
     */
    public function tokens(): PayPalTokenProvider
    {
        return $this->tokens;
    }

    /**
     * Orders v2.
     *
     * @since 1.0.0
     *
     * @return OrdersApi
     */
    public function orders(): OrdersApi
    {
        return new OrdersApi( $this );
    }

    /**
     * Payments v2 (authorizations, captures, refunds).
     *
     * @since 1.0.0
     *
     * @return PaymentsApi
     */
    public function payments(): PaymentsApi
    {
        return new PaymentsApi( $this );
    }

    /**
     * Vault v3 (payment tokens, setup tokens).
     *
     * @since 1.0.0
     *
     * @return VaultApi
     */
    public function vault(): VaultApi
    {
        return new VaultApi( $this );
    }

    /**
     * Customer Disputes v1 (read-only).
     *
     * @since 1.0.0
     *
     * @return DisputesApi
     */
    public function disputes(): DisputesApi
    {
        return new DisputesApi( $this );
    }

    /**
     * Webhooks v1 (subscriptions, signature verification, events).
     *
     * @since 1.0.0
     *
     * @return WebhooksApi
     */
    public function webhooks(): WebhooksApi
    {
        return new WebhooksApi( $this );
    }

    /**
     * Mints an `id_token` for the JS SDK (spec §10.2).
     *
     * @since 1.0.0
     *
     * @param  string|null  $targetCustomerId  PayPal customer id, when the shopper has one.
     *
     * @throws PayPalApiException On failure.
     *
     * @return string
     */
    public function idToken( ?string $targetCustomerId = null ): string
    {
        $credentials = $this->credentials();
        $attempt     = 0;

        while ( true ) {
            try {
                return $this->tokens->idToken( $credentials, $targetCustomerId );
            } catch ( PayPalApiException $failure ) {
                $this->logFailure( 'POST', '/v1/oauth2/token', $failure, $attempt );

                if ( ! $failure->isRetryable() || $attempt >= $this->retries() || ! $this->backoff( ++$attempt, $failure->retryAfter ) ) {
                    throw $failure;
                }
            }
        }
    }

    /**
     * Downloads a webhook signing certificate (spec §8.1).
     *
     * The caller checks the URL against PayPal's certificate host first.
     * The download uses the client's timeouts with TLS verification on and
     * sends no credentials: PayPal serves certificates unauthenticated.
     *
     * @since 1.0.0
     *
     * @param  string  $url  HTTPS certificate URL from `PAYPAL-CERT-URL`.
     *
     * @throws PayPalApiException When it can't be fetched.
     *
     * @return string The PEM-encoded certificate (chain).
     */
    public function certificate( string $url ): string
    {
        try {
            $response = $this->http
                ->timeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.timeout', 15 ) ) )
                ->connectTimeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.connect_timeout', 5 ) ) )
                ->withOptions( [ 'verify' => true, 'allow_redirects' => false ] )
                ->get( $url );
        } catch ( ConnectionException $exception ) {
            throw PayPalApiException::connectionFailed( $exception );
        }

        if ( ! $response->successful() || '' === trim( $response->body() ) ) {
            throw new PayPalApiException(
                sprintf( 'PayPal returned HTTP %d for a webhook certificate.', $response->status() ),
                $response->status(),
                'CERTIFICATE_UNAVAILABLE',
                debugId: $response->header( 'PayPal-Debug-Id' ) ?: null,
            );
        }

        return $response->body();
    }

    /**
     * Sends a read-only `GET`.
     *
     * @since 1.0.0
     *
     * @param  string                $path   API path (`/v2/checkout/orders/…`).
     * @param  array<string, mixed>  $query  Query string.
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function get( string $path, array $query = [] ): array
    {
        return $this->send( 'GET', $path, query: $query );
    }

    /**
     * Sends a mutating `POST`.
     *
     * @since 1.0.0
     *
     * @param  string                $path       API path.
     * @param  array<string, mixed>  $body       JSON body.
     * @param  string                $requestId  `PayPal-Request-Id`, stable across retries of the same operation.
     * @param  array<string, string>  $headers   Extra headers (`Prefer`, …).
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function post( string $path, array $body, string $requestId, array $headers = [] ): array
    {
        return $this->send( 'POST', $path, $body, requestId: $requestId, headers: $headers );
    }

    /**
     * Sends a `POST` that changes nothing at PayPal (signature
     * verification), so it needs no request id.
     *
     * @since 1.0.0
     *
     * @param  string                $path  API path.
     * @param  array<string, mixed>  $body  JSON body.
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function postReadOnly( string $path, array $body ): array
    {
        return $this->send( 'POST', $path, $body, readOnly: true );
    }

    /**
     * Sends a read-only `POST` whose body is already-encoded JSON, sent
     * byte for byte (signature verification, which needs the event exactly
     * as PayPal delivered it). Auth, the single 401 refresh, retries, and
     * redacted logging apply as for {@see self::postReadOnly()}.
     *
     * @since 1.0.0
     *
     * @param  string  $path  API path.
     * @param  string  $json  JSON body, sent as is.
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function postRawReadOnly( string $path, string $json ): array
    {
        return $this->send( 'POST', $path, readOnly: true, rawBody: $json );
    }

    /**
     * Sends a mutating `PATCH` (a JSON Patch list).
     *
     * @since 1.0.0
     *
     * @param  string                         $path       API path.
     * @param  array<int, array<string, mixed>>  $operations  JSON Patch operations.
     * @param  string                         $requestId  `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function patch( string $path, array $operations, string $requestId ): array
    {
        return $this->send( 'PATCH', $path, $operations, requestId: $requestId );
    }

    /**
     * Sends a mutating `DELETE`.
     *
     * @since 1.0.0
     *
     * @param  string  $path       API path.
     * @param  string  $requestId  `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<string, mixed>
     */
    public function delete( string $path, string $requestId ): array
    {
        return $this->send( 'DELETE', $path, requestId: $requestId );
    }

    /**
     * Sends a request with auth, idempotency, retries, and logging.
     *
     * @since 1.0.0
     *
     * @param  string                   $method     HTTP method.
     * @param  string                   $path       API path, starting with `/`.
     * @param  array<array-key, mixed>|null  $body  JSON body, or null for none.
     * @param  array<string, mixed>     $query      Query string.
     * @param  string|null              $requestId  `PayPal-Request-Id`; required for mutating methods.
     * @param  array<string, string>    $headers    Extra headers.
     * @param  bool                     $readOnly   Whether a `POST` changes nothing (no request id needed).
     * @param  string|null              $rawBody    Already-encoded JSON body, sent as is instead of `$body`.
     *
     * @throws InvalidArgumentException When a mutating call has no request id.
     * @throws PayPalApiException       On failure.
     *
     * @return array<string, mixed>
     */
    protected function send(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?string $requestId = null,
        array $headers = [],
        bool $readOnly = false,
        ?string $rawBody = null,
    ): array {
        $method = strtoupper( $method );

        if ( ! $readOnly && in_array( $method, self::MUTATING_METHODS, true ) && ( null === $requestId || '' === trim( $requestId ) ) ) {
            throw new InvalidArgumentException( sprintf( 'PayPal %s %s needs a PayPal-Request-Id.', $method, $path ) );
        }

        if ( null !== $requestId && '' !== $requestId ) {
            $headers['PayPal-Request-Id'] = $requestId;
        }

        $credentials = $this->credentials();
        $url         = $credentials->environment->apiBase() . '/' . ltrim( $path, '/' );
        $retries     = $this->retries();
        $attempt     = 0;
        $refreshed   = false;

        while ( true ) {
            $this->logRequest( $method, $path, $headers, null === $rawBody ? $body : self::decodeForLog( $rawBody ) );

            try {
                $response = $this->pending( $credentials, $headers, $query )->send( $method, $url, $this->options( $method, $body, $rawBody ) );
            } catch ( ConnectionException|PayPalApiException $exception ) {
                // Both the call and the token fetch before it can fail; only
                // failures worth repeating are retried.
                $failure = $exception instanceof PayPalApiException ? $exception : PayPalApiException::connectionFailed( $exception );
                $this->logFailure( $method, $path, $failure, $attempt );

                if ( $failure->isRetryable() && $attempt < $retries && $this->backoff( ++$attempt, $failure->retryAfter ) ) {
                    continue;
                }

                throw $failure;
            }

            $decoded = $this->decode( $response );

            $this->logResponse( $method, $path, $response, $decoded );

            if ( 401 === $response->status() && ! $refreshed ) {
                // The cached token was revoked or expired early: fetch a new
                // one and replay once. This doesn't count as a retry.
                $refreshed = true;

                $this->logger()->warning( 'PayPal rejected the access token; fetching a new one.', [
                    'method'   => $method,
                    'path'     => PayPalRedactor::redactPath( $path ),
                    'mode'     => $credentials->environment->mode,
                    'status'   => $response->status(),
                    'debug_id' => $response->header( 'PayPal-Debug-Id' ) ?: null,
                ] );

                $this->tokens->forget( $credentials );

                continue;
            }

            if ( $response->successful() ) {
                return $decoded;
            }

            $failure = PayPalApiException::fromResponse(
                $response->status(),
                $decoded,
                $response->header( 'PayPal-Debug-Id' ) ?: null,
                $response->header( 'Retry-After' ) ?: null,
            );
            $this->logFailure( $method, $path, $failure, $attempt );

            if ( $failure->isRetryable() && $attempt < $retries && $this->backoff( ++$attempt, $failure->retryAfter ) ) {
                continue;
            }

            throw $failure;
        }
    }

    /**
     * A pending request with auth, timeouts, TLS verification, and headers.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials     $credentials  Credentials.
     * @param  array<string, string>  $headers     Headers.
     * @param  array<string, mixed>  $query        Query string.
     *
     * @throws PayPalApiException When no token could be obtained.
     *
     * @return PendingRequest
     */
    protected function pending( PayPalCredentials $credentials, array $headers, array $query ): PendingRequest
    {
        $request = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken( $this->tokens->token( $credentials ) )
            ->withHeaders( $headers )
            ->timeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.timeout', 15 ) ) )
            ->connectTimeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.connect_timeout', 5 ) ) )
            ->withOptions( [ 'verify' => true ] );

        return [] === $query ? $request : $request->withQueryParameters( $query );
    }

    /**
     * The Guzzle options for a request body.
     *
     * An empty `POST` body goes out as `{}`: PayPal rejects `[]` on the
     * capture, authorize, reauthorize, and refund calls that take an
     * optional body. A raw body is sent as is, with the JSON content type.
     *
     * @since 1.0.0
     *
     * @param  string                       $method   HTTP method.
     * @param  array<array-key, mixed>|null  $body     JSON body, or null for none.
     * @param  string|null                  $rawBody  Already-encoded JSON body, sent as is.
     *
     * @return array<string, mixed>
     */
    protected function options( string $method, ?array $body, ?string $rawBody = null ): array
    {
        if ( null !== $rawBody ) {
            return [ 'body' => $rawBody ];
        }

        if ( null === $body ) {
            return [];
        }

        return [ 'json' => 'POST' === $method && [] === $body ? new stdClass() : $body ];
    }

    /**
     * How many times a failed call is retried.
     *
     * @since 1.0.0
     *
     * @return int
     */
    protected function retries(): int
    {
        return max( 0, (int) config( 'artisanpack.ecommerce-paypal.http.retries', 2 ) );
    }

    /**
     * Waits before retry number `$attempt`: exponential backoff with full
     * jitter, or the `Retry-After` PayPal asked for when that is longer.
     *
     * A `Retry-After` beyond {@see self::BACKOFF_MAX_MS} isn't waited out:
     * the call gives up so a request isn't held open that long.
     *
     * @since 1.0.0
     *
     * @param  int          $attempt     Retry number, from 1.
     * @param  string|null  $retryAfter  The `Retry-After` header, when PayPal sent one.
     *
     * @return bool Whether to retry.
     */
    protected function backoff( int $attempt, ?string $retryAfter = null ): bool
    {
        $ceiling   = min( self::BACKOFF_MAX_MS, self::BACKOFF_BASE_MS * ( 2 ** ( $attempt - 1 ) ) );
        $delay     = random_int( intdiv( $ceiling, 2 ), $ceiling );
        $requested = $this->retryAfterMs( $retryAfter );

        if ( null !== $requested ) {
            if ( $requested > self::BACKOFF_MAX_MS ) {
                return false;
            }

            $delay = max( $delay, $requested );
        }

        Sleep::for( $delay )->milliseconds();

        return true;
    }

    /**
     * Parses `Retry-After` (delay seconds or an HTTP date) into milliseconds.
     *
     * @since 1.0.0
     *
     * @param  string|null  $retryAfter  Header value.
     *
     * @return int|null Null when absent or unparseable.
     */
    protected function retryAfterMs( ?string $retryAfter ): ?int
    {
        $retryAfter = trim( (string) $retryAfter );

        if ( '' === $retryAfter ) {
            return null;
        }

        if ( ctype_digit( $retryAfter ) ) {
            return (int) $retryAfter * 1000;
        }

        $date = DateTimeImmutable::createFromFormat( DATE_RFC7231, $retryAfter );

        if ( false === $date ) {
            return null;
        }

        return max( 0, ( $date->getTimestamp() - Date::now()->getTimestamp() ) * 1000 );
    }

    /**
     * Decodes a response body, tolerating empty and non-JSON bodies.
     *
     * @since 1.0.0
     *
     * @param  Response  $response  Response.
     *
     * @return array<string, mixed>
     */
    protected function decode( Response $response ): array
    {
        if ( '' === trim( $response->body() ) ) {
            return [];
        }

        try {
            $decoded = $response->json();
        } catch ( Throwable ) {
            return [];
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * A raw JSON body decoded for the debug log (it is redacted there), or
     * null when it isn't a JSON object or list.
     *
     * @since 1.0.0
     *
     * @param  string  $json  Raw JSON body.
     *
     * @return array<array-key, mixed>|null
     */
    protected static function decodeForLog( string $json ): ?array
    {
        $decoded = json_decode( $json, true );

        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Logs an outgoing request when debug logging is on.
     *
     * @since 1.0.0
     *
     * @param  string                       $method   HTTP method.
     * @param  string                       $path     API path.
     * @param  array<string, string>        $headers  Headers (without auth).
     * @param  array<array-key, mixed>|null  $body    JSON body.
     *
     * @return void
     */
    protected function logRequest( string $method, string $path, array $headers, ?array $body ): void
    {
        if ( ! $this->debugLogging() ) {
            return;
        }

        $this->logger()->debug( 'PayPal API request.', [
            'method'  => $method,
            'path'    => PayPalRedactor::redactPath( $path ),
            'mode'    => $this->environment()->mode,
            'headers' => PayPalRedactor::redactHeaders( $headers ),
            'body'    => null === $body ? null : PayPalRedactor::redact( $body ),
        ] );
    }

    /**
     * Logs a response when debug logging is on.
     *
     * @since 1.0.0
     *
     * @param  string                $method   HTTP method.
     * @param  string                $path     API path.
     * @param  Response              $response  Response.
     * @param  array<string, mixed>  $decoded  Decoded body.
     *
     * @return void
     */
    protected function logResponse( string $method, string $path, Response $response, array $decoded ): void
    {
        if ( ! $this->debugLogging() ) {
            return;
        }

        $this->logger()->debug( 'PayPal API response.', [
            'method'   => $method,
            'path'     => PayPalRedactor::redactPath( $path ),
            'status'   => $response->status(),
            'debug_id' => $response->header( 'PayPal-Debug-Id' ) ?: null,
            'body'     => PayPalRedactor::isVaultPath( $path ) ? PayPalRedactor::redactVault( $decoded ) : PayPalRedactor::redact( $decoded ),
        ] );
    }

    /**
     * Logs a failed call. Always on: support needs the debug id.
     *
     * @since 1.0.0
     *
     * @param  string              $method     HTTP method.
     * @param  string              $path       API path.
     * @param  PayPalApiException  $exception  Failure.
     * @param  int                 $attempt    Retries made so far.
     *
     * @return void
     */
    protected function logFailure( string $method, string $path, PayPalApiException $exception, int $attempt ): void
    {
        $this->logger()->warning( 'PayPal API call failed.', [
            'method'  => $method,
            'path'    => PayPalRedactor::redactPath( $path ),
            'mode'    => $this->environment()->mode,
            'attempt' => $attempt + 1,
            ...$exception->context(),
        ] );
    }

    /**
     * Whether bodies are logged.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function debugLogging(): bool
    {
        return (bool) config( 'artisanpack.ecommerce-paypal.logging.debug', false );
    }

    /**
     * The configured log channel.
     *
     * @since 1.0.0
     *
     * @return LoggerInterface
     */
    protected function logger(): LoggerInterface
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return Log::channel( is_string( $channel ) && '' !== $channel ? $channel : null );
    }
}
