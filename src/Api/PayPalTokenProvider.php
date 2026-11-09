<?php

/**
 * PayPal OAuth2 access tokens.
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

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalAccessToken;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Fetches client-credentials access tokens from `/v1/oauth2/token` and
 * caches them per mode and client id until 60 seconds before they expire.
 *
 * The cache key never contains the secret. A `401` from any API call makes
 * {@see PayPalClient} {@see self::forget()} the token and fetch a new one
 * once.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalTokenProvider
{
    /**
     * Cache key prefix.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_PREFIX = 'artisanpack.ecommerce-paypal.token.';

    /**
     * Seconds before expiry a cached token is considered stale.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXPIRY_MARGIN = 60;

    /**
     * Creates the provider.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory      $http   HTTP client factory.
     * @param  CacheRepository  $cache  Token cache.
     */
    public function __construct(
        protected HttpFactory $http,
        protected CacheRepository $cache,
    ) {
    }

    /**
     * A valid access token for `$credentials`, from the cache or PayPal.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @throws PayPalApiException When PayPal refuses the credentials or can't be reached.
     *
     * @return string
     */
    public function token( PayPalCredentials $credentials ): string
    {
        $cached = $this->cache->get( $this->cacheKey( $credentials ) );

        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $token = $this->fetch( $credentials );
        $ttl   = $token->expiresIn - self::EXPIRY_MARGIN;

        if ( $ttl > 0 ) {
            $this->cache->put( $this->cacheKey( $credentials ), $token->accessToken, $ttl );
        }

        return $token->accessToken;
    }

    /**
     * Forgets the cached token for `$credentials`.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @return void
     */
    public function forget( PayPalCredentials $credentials ): void
    {
        $this->cache->forget( $this->cacheKey( $credentials ) );
    }

    /**
     * Requests a new access token, bypassing the cache.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @throws PayPalApiException When PayPal refuses the credentials or can't be reached.
     *
     * @return PayPalAccessToken
     */
    public function fetch( PayPalCredentials $credentials ): PayPalAccessToken
    {
        return PayPalAccessToken::fromArray( $this->post( $credentials, [ 'grant_type' => 'client_credentials' ] ) );
    }

    /**
     * Mints an `id_token` that identifies a vaulted PayPal customer to the
     * JS SDK (`data-user-id-token`, spec §10.2).
     *
     * Without a customer id, the token lets the SDK save a first payment
     * method.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials       Credentials.
     * @param  string|null        $targetCustomerId  PayPal customer id, when the shopper has one.
     *
     * @throws PayPalApiException When PayPal refuses or can't be reached.
     *
     * @return string
     */
    public function idToken( PayPalCredentials $credentials, ?string $targetCustomerId = null ): string
    {
        $form = [ 'grant_type' => 'client_credentials', 'response_type' => 'id_token' ];

        if ( null !== $targetCustomerId && '' !== $targetCustomerId ) {
            $form['target_customer_id'] = $targetCustomerId;
        }

        $body = $this->post( $credentials, $form );

        if ( ! isset( $body['id_token'] ) || ! is_string( $body['id_token'] ) || '' === $body['id_token'] ) {
            throw new PayPalApiException( 'PayPal did not return an id_token.', 200, 'MISSING_ID_TOKEN' );
        }

        return $body['id_token'];
    }

    /**
     * Whether PayPal issues a token for `$credentials`.
     *
     * Returns false only when PayPal refuses the credentials; anything else
     * that goes wrong (PayPal unreachable, a 5xx) is thrown, so a caller can
     * tell "wrong credentials" from "couldn't check".
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @throws PayPalApiException When the check itself failed.
     *
     * @return bool
     */
    public function verify( PayPalCredentials $credentials ): bool
    {
        if ( ! $credentials->isComplete() ) {
            return false;
        }

        try {
            $this->fetch( $credentials );
        } catch ( PayPalApiException $exception ) {
            if ( $exception->isAuthenticationFailure() || 400 === $exception->status ) {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /**
     * Posts a form to the token endpoint.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials     $credentials  Credentials.
     * @param  array<string, string>  $form         Form fields.
     *
     * @throws PayPalApiException On any failure.
     *
     * @return array<string, mixed>
     */
    protected function post( PayPalCredentials $credentials, array $form ): array
    {
        try {
            $response = $this->request()
                ->withBasicAuth( $credentials->clientId, $credentials->secret() )
                ->asForm()
                ->post( $credentials->environment->apiBase() . '/v1/oauth2/token', $form );
        } catch ( ConnectionException $exception ) {
            throw PayPalApiException::connectionFailed( $exception );
        }

        $body = $this->decode( $response );

        if ( ! $response->successful() ) {
            throw PayPalApiException::fromResponse( $response->status(), $body, $response->header( 'PayPal-Debug-Id' ) ?: null );
        }

        return $body;
    }

    /**
     * A request with the configured timeouts and TLS verification on.
     *
     * @since 1.0.0
     *
     * @return PendingRequest
     */
    protected function request(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->timeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.timeout', 15 ) ) )
            ->connectTimeout( max( 1, (int) config( 'artisanpack.ecommerce-paypal.http.connect_timeout', 5 ) ) )
            ->withOptions( [ 'verify' => true ] );
    }

    /**
     * The cache key for `$credentials`.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Credentials.
     *
     * @return string
     */
    protected function cacheKey( PayPalCredentials $credentials ): string
    {
        return self::CACHE_PREFIX . $credentials->cacheKey();
    }

    /**
     * Decodes a response body, tolerating non-JSON.
     *
     * @since 1.0.0
     *
     * @param  Response  $response  Response.
     *
     * @return array<string, mixed>
     */
    protected function decode( Response $response ): array
    {
        try {
            $body = $response->json();
        } catch ( Throwable ) {
            return [];
        }

        return is_array( $body ) ? $body : [];
    }
}
