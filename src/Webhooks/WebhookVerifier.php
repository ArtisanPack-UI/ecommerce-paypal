<?php

/**
 * PayPal webhook signature verification.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks;

use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use JsonException;
use OpenSSLCertificate;
use Throwable;

/**
 * Verifies that a webhook delivery came from PayPal (spec §2.2 D7, §8.1).
 *
 * PayPal's SDKs offer no verification, so the package owns it. A delivery
 * is rejected, each with its own {@see WebhookResult::unverified()} code,
 * when:
 *
 * - a `PAYPAL-TRANSMISSION-*`, `PAYPAL-CERT-URL`, or `PAYPAL-AUTH-ALGO`
 *   header is missing (`missing_headers`);
 * - the algorithm isn't `SHA256withRSA` (`unsupported_algorithm`);
 * - the transmission time is unreadable or further from now than
 *   `webhook_tolerance_seconds` (3 days by default; `0` turns the check
 *   off) (`stale_transmission`);
 * - the certificate URL isn't HTTPS on PayPal's certificate host for the
 *   current mode, at a `/v1/notifications/certs/CERT-…` path
 *   (`invalid_cert_url`);
 * - no webhook id is configured (`webhook_id_missing`);
 * - the body isn't a JSON event (`invalid_payload`).
 *
 * Then, in `local` mode (the default), the certificate is fetched through
 * the client, checked for its validity dates (`certificate_expired`) and a
 * PayPal subject (`certificate_untrusted`), cached until it expires, and
 * `transmission_id|transmission_time|webhook_id|crc32(body)` is checked
 * against the signature for each configured webhook id
 * (`signature_mismatch` when none matches). A certificate that couldn't be
 * downloaded or used is remembered for {@see self::MISS_SECONDS}, so
 * unsigned requests can't make the store download it again and again. In
 * `postback` mode PayPal's `verify-webhook-signature` endpoint decides
 * instead, given the body exactly as it was received.
 *
 * A failure logs its code and the transmission id, never the body.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class WebhookVerifier
{
    /**
     * The only signing algorithm PayPal uses.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ALGORITHM = 'SHA256withRSA';

    /**
     * How far the transmission time may be from now, in seconds, when
     * `webhook_tolerance_seconds` isn't configured: 3 days, PayPal's retry
     * horizon (it redelivers up to 25 times over 3 days).
     *
     * Pending the sandbox recording (audit P-HOOK-5/6): if PayPal re-signs
     * each retry with a new transmission time, this can drop back to 300.
     * Until that is confirmed, a long window is the safe default: the
     * signature binds the transmission id and time, and the engine drops
     * event ids it has already processed, so a replay can't act twice.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const TOLERANCE_SECONDS = 259200;

    /**
     * How long a certificate that couldn't be downloaded or used is
     * remembered, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MISS_SECONDS = 600;

    /**
     * The path PayPal's signing certificates are served from.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CERTIFICATE_PATH = '#^/v1/notifications/certs/CERT-[A-Za-z0-9-]{1,128}$#';

    /**
     * Cache key prefix for downloaded certificates.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CACHE_PREFIX = 'ecommerce-paypal:webhook-certificate:';

    /**
     * The headers every delivery carries, by transmission field.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const HEADERS = [
        'transmission_id'   => 'PAYPAL-TRANSMISSION-ID',
        'transmission_time' => 'PAYPAL-TRANSMISSION-TIME',
        'transmission_sig'  => 'PAYPAL-TRANSMISSION-SIG',
        'cert_url'          => 'PAYPAL-CERT-URL',
        'auth_algo'         => 'PAYPAL-AUTH-ALGO',
    ];

    /**
     * Creates the verifier.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     * @param  Cache         $cache   Certificate cache.
     */
    public function __construct(
        protected PayPalClient $client,
        protected Cache $cache,
    ) {
    }

    /**
     * Verifies `$request`.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Inbound webhook request.
     *
     * @return WebhookResult A verified result carrying the event type, id, and payload, or an unverified one.
     */
    public function verify( Request $request ): WebhookResult
    {
        $transmission = [];

        foreach ( self::HEADERS as $field => $header ) {
            $value = trim( (string) $request->header( $header, '' ) );

            if ( '' === $value ) {
                return $this->reject( 'missing_headers', __( 'The PayPal webhook is missing its signature headers.' ) );
            }

            $transmission[ $field ] = $value;
        }

        $transmissionId = $transmission['transmission_id'];

        if ( self::ALGORITHM !== $transmission['auth_algo'] ) {
            return $this->reject( 'unsupported_algorithm', __( 'The PayPal webhook uses an unsupported signing algorithm.' ), $transmissionId );
        }

        if ( ! $this->isFresh( $transmission['transmission_time'] ) ) {
            return $this->reject( 'stale_transmission', __( 'The PayPal webhook was sent too long ago, or its time is unreadable.' ), $transmissionId );
        }

        if ( ! $this->isTrustedCertificateUrl( $transmission['cert_url'] ) ) {
            return $this->reject( 'invalid_cert_url', __( 'The PayPal webhook certificate is not hosted by PayPal.' ), $transmissionId );
        }

        $webhookIds = self::webhookIds();

        if ( [] === $webhookIds ) {
            return $this->reject( 'webhook_id_missing', __( 'No PayPal webhook id is configured, so the webhook cannot be verified.' ), $transmissionId );
        }

        $body = $request->getContent();

        try {
            $event = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException ) {
            $event = null;
        }

        if ( ! is_array( $event ) || ! isset( $event['id'], $event['event_type'] ) || ! is_string( $event['id'] ) || ! is_string( $event['event_type'] ) ) {
            return $this->reject( 'invalid_payload', __( 'The PayPal webhook body is not a PayPal event.' ), $transmissionId );
        }

        $failure = 'postback' === self::mode()
            ? $this->verifyByPostback( $transmission, $webhookIds, $body )
            : $this->verifyLocally( $transmission, $webhookIds, $body );

        if ( null !== $failure ) {
            return $failure;
        }

        return WebhookResult::verified( $event['event_type'], $event['id'], $event );
    }

    /**
     * Whether `$url` is an HTTPS URL on PayPal's certificate host for the
     * current mode (`api.paypal.com` live, `api.sandbox.paypal.com`
     * sandbox), with no credentials, port, query, or fragment, at a
     * certificate path ({@see self::CERTIFICATE_PATH}).
     *
     * @since 1.0.0
     *
     * @param  string  $url  Certificate URL.
     *
     * @return bool
     */
    public function isTrustedCertificateUrl( string $url ): bool
    {
        $parts = parse_url( $url );

        if ( ! is_array( $parts ) ) {
            return false;
        }

        return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
            && strtolower( (string) ( $parts['host'] ?? '' ) ) === $this->client->environment()->certificateHost()
            && ! isset( $parts['user'] )
            && ! isset( $parts['pass'] )
            && ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] )
            && ! isset( $parts['query'] )
            && ! isset( $parts['fragment'] )
            && 1 === preg_match( self::CERTIFICATE_PATH, (string) ( $parts['path'] ?? '' ) );
    }

    /**
     * The configured webhook ids (several during a rotation).
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function webhookIds(): array
    {
        $configured = config( 'artisanpack.ecommerce-paypal.webhook_id' );
        $ids        = is_array( $configured ) ? $configured : explode( ',', (string) $configured );

        return array_values( array_unique( array_filter(
            array_map( static fn ( mixed $id ): string => trim( (string) $id ), $ids ),
            static fn ( string $id ): bool => '' !== $id,
        ) ) );
    }

    /**
     * How far the transmission time may be from now, in seconds
     * (`webhook_tolerance_seconds`, {@see self::TOLERANCE_SECONDS} by
     * default). `0` turns the check off.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public static function toleranceSeconds(): int
    {
        $configured = config( 'artisanpack.ecommerce-paypal.webhook_tolerance_seconds' );

        return is_numeric( $configured ) ? max( 0, (int) $configured ) : self::TOLERANCE_SECONDS;
    }

    /**
     * The verification mode: `local` or `postback`.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function mode(): string
    {
        return 'postback' === strtolower( trim( (string) config( 'artisanpack.ecommerce-paypal.webhook_verification', 'local' ) ) ) ? 'postback' : 'local';
    }

    /**
     * The string PayPal signs for `$webhookId`.
     *
     * @since 1.0.0
     *
     * @param  string  $transmissionId    `PAYPAL-TRANSMISSION-ID`.
     * @param  string  $transmissionTime  `PAYPAL-TRANSMISSION-TIME`.
     * @param  string  $webhookId         Webhook id.
     * @param  string  $body              Raw body.
     *
     * @return string
     */
    public static function signedString( string $transmissionId, string $transmissionTime, string $webhookId, string $body ): string
    {
        return $transmissionId . '|' . $transmissionTime . '|' . $webhookId . '|' . sprintf( '%u', crc32( $body ) );
    }

    /**
     * Checks the signature against PayPal's certificate.
     *
     * @since 1.0.0
     *
     * @param  array<string, string>  $transmission  Header values.
     * @param  array<int, string>     $webhookIds    Configured webhook ids.
     * @param  string                 $body          Raw body.
     *
     * @return WebhookResult|null Null when it verifies.
     */
    protected function verifyLocally( array $transmission, array $webhookIds, string $body ): ?WebhookResult
    {
        $transmissionId = $transmission['transmission_id'];
        $signature      = base64_decode( $transmission['transmission_sig'], true );

        if ( false === $signature || '' === $signature ) {
            return $this->reject( 'signature_mismatch', __( 'The PayPal webhook signature does not match.' ), $transmissionId );
        }

        $certificate = $this->certificate( $transmission['cert_url'], $transmissionId );

        if ( $certificate instanceof WebhookResult ) {
            return $certificate;
        }

        foreach ( $webhookIds as $webhookId ) {
            $signed = self::signedString( $transmissionId, $transmission['transmission_time'], $webhookId, $body );

            if ( 1 === openssl_verify( $signed, $signature, $certificate, OPENSSL_ALGO_SHA256 ) ) {
                return null;
            }
        }

        return $this->reject( 'signature_mismatch', __( 'The PayPal webhook signature does not match.' ), $transmissionId );
    }

    /**
     * Asks PayPal to verify the delivery, once per configured webhook id.
     *
     * A webhook id PayPal refuses outright (an old id it no longer knows,
     * during a rotation) doesn't stop the others being tried. The delivery
     * is rejected as `verification_unavailable`, so PayPal delivers it
     * again, only when a check failed in a way worth repeating and no id
     * verified; otherwise it is a `signature_mismatch`.
     *
     * @since 1.0.0
     *
     * @param  array<string, string>  $transmission  Header values.
     * @param  array<int, string>     $webhookIds    Configured webhook ids.
     * @param  string                 $body          Raw body, exactly as received.
     *
     * @return WebhookResult|null Null when it verifies.
     */
    protected function verifyByPostback( array $transmission, array $webhookIds, string $body ): ?WebhookResult
    {
        $unavailable = null;

        foreach ( $webhookIds as $webhookId ) {
            try {
                if ( $this->client->webhooks()->verifySignature( $transmission, $webhookId, $body ) ) {
                    return null;
                }
            } catch ( PayPalApiException $exception ) {
                if ( $exception->isRetryable() ) {
                    $unavailable = $exception;
                }
            } catch ( JsonException ) {
                continue;
            }
        }

        if ( null !== $unavailable ) {
            return $this->reject( 'verification_unavailable', __( 'PayPal could not verify the webhook. It will be delivered again.' ), $transmission['transmission_id'], $unavailable->debugId );
        }

        return $this->reject( 'signature_mismatch', __( 'The PayPal webhook signature does not match.' ), $transmission['transmission_id'] );
    }

    /**
     * The signing certificate at `$url`: from the cache, or downloaded,
     * checked, and cached until it expires.
     *
     * A certificate that couldn't be downloaded or used is remembered, with
     * its rejection, for {@see self::MISS_SECONDS}, so it isn't downloaded
     * again for every delivery that names it.
     *
     * @since 1.0.0
     *
     * @param  string  $url             Trusted certificate URL.
     * @param  string  $transmissionId  For the log line.
     *
     * @return OpenSSLCertificate|WebhookResult The certificate, or the rejection.
     */
    protected function certificate( string $url, string $transmissionId ): OpenSSLCertificate|WebhookResult
    {
        $hash   = hash( 'sha256', $url );
        $key    = self::CACHE_PREFIX . $hash;
        $miss   = self::CACHE_PREFIX . 'miss:' . $hash;
        $missed = $this->cache->get( $miss );

        if ( is_string( $missed ) && null !== self::certificateProblem( $missed ) ) {
            return $this->reject( self::certificateCode( $missed ), (string) self::certificateProblem( $missed ), $transmissionId );
        }

        $cached = $this->cache->get( $key );
        $pem    = is_string( $cached ) ? $cached : null;

        if ( null === $pem ) {
            try {
                $pem = $this->client->certificate( $url );
            } catch ( PayPalApiException $exception ) {
                return $this->miss( $key, $miss, 'certificate_unavailable', $transmissionId, $exception->debugId );
            }
        }

        $certificate = @openssl_x509_read( $pem );
        $details     = false === $certificate ? false : openssl_x509_parse( $certificate );

        if ( false === $certificate || ! is_array( $details ) ) {
            return $this->miss( $key, $miss, 'certificate_unreadable', $transmissionId );
        }

        $now       = Date::now()->getTimestamp();
        $validFrom = (int) ( $details['validFrom_time_t'] ?? 0 );
        $validTo   = (int) ( $details['validTo_time_t'] ?? 0 );

        if ( $now < $validFrom || $now >= $validTo ) {
            return $this->miss( $key, $miss, 'certificate_expired', $transmissionId );
        }

        if ( ! self::hasPayPalSubject( $details ) ) {
            return $this->miss( $key, $miss, 'certificate_untrusted', $transmissionId );
        }

        if ( ! is_string( $cached ) ) {
            $this->cache->put( $key, $pem, CarbonImmutable::createFromTimestamp( $validTo ) );
        }

        return $certificate;
    }

    /**
     * Forgets the cached certificate, remembers the problem for
     * {@see self::MISS_SECONDS}, and returns the rejection.
     *
     * @since 1.0.0
     *
     * @param  string       $key             Certificate cache key.
     * @param  string       $miss            Miss cache key.
     * @param  string       $problem         A {@see self::certificateProblem()} key.
     * @param  string       $transmissionId  For the log line.
     * @param  string|null  $debugId         PayPal debug id, when the download failed.
     *
     * @return WebhookResult
     */
    protected function miss( string $key, string $miss, string $problem, string $transmissionId, ?string $debugId = null ): WebhookResult
    {
        $this->cache->forget( $key );
        $this->cache->put( $miss, $problem, self::MISS_SECONDS );

        return $this->reject( self::certificateCode( $problem ), (string) self::certificateProblem( $problem ), $transmissionId, $debugId );
    }

    /**
     * The translated reason for a certificate problem, or null for an
     * unknown key.
     *
     * @since 1.0.0
     *
     * @param  string  $problem  `certificate_unavailable`, `certificate_unreadable`, `certificate_expired`, or `certificate_untrusted`.
     *
     * @return string|null
     */
    protected static function certificateProblem( string $problem ): ?string
    {
        return match ( $problem ) {
            'certificate_unavailable' => __( 'The PayPal webhook certificate could not be downloaded. It will be delivered again.' ),
            'certificate_unreadable'  => __( 'The PayPal webhook certificate could not be read.' ),
            'certificate_expired'     => __( 'The PayPal webhook certificate is expired or not yet valid.' ),
            'certificate_untrusted'   => __( 'The PayPal webhook certificate was not issued to PayPal.' ),
            default                   => null,
        };
    }

    /**
     * The rejection code for a certificate problem (an unreadable
     * certificate is reported as `certificate_untrusted`).
     *
     * @since 1.0.0
     *
     * @param  string  $problem  A {@see self::certificateProblem()} key.
     *
     * @return string
     */
    protected static function certificateCode( string $problem ): string
    {
        return 'certificate_unreadable' === $problem ? 'certificate_untrusted' : $problem;
    }

    /**
     * Whether the certificate's common name is a `paypal.com` host.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $details  `openssl_x509_parse()` output.
     *
     * @return bool
     */
    protected static function hasPayPalSubject( array $details ): bool
    {
        $names = (array) ( $details['subject']['CN'] ?? [] );

        foreach ( $names as $name ) {
            $name = strtolower( trim( (string) $name ) );

            if ( 'paypal.com' === $name || str_ends_with( $name, '.paypal.com' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$time` is a readable time within the tolerance of now
     * ({@see self::toleranceSeconds()}).
     *
     * @since 1.0.0
     *
     * @param  string  $time  `PAYPAL-TRANSMISSION-TIME`.
     *
     * @return bool
     */
    protected function isFresh( string $time ): bool
    {
        try {
            $sent = CarbonImmutable::parse( $time );
        } catch ( Throwable ) {
            return false;
        }

        $tolerance = self::toleranceSeconds();

        return 0 === $tolerance || abs( Date::now()->getTimestamp() - $sent->getTimestamp() ) <= $tolerance;
    }

    /**
     * Logs a rejection (code and transmission id only) and returns it.
     *
     * @since 1.0.0
     *
     * @param  string       $code            Rejection code.
     * @param  string       $message         Translated reason.
     * @param  string|null  $transmissionId  Transmission id, once known.
     * @param  string|null  $debugId         PayPal debug id, when a PayPal call failed.
     *
     * @return WebhookResult
     */
    protected function reject( string $code, string $message, ?string $transmissionId = null, ?string $debugId = null ): WebhookResult
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        Log::channel( is_string( $channel ) && '' !== $channel ? $channel : null )->warning( 'Rejected a PayPal webhook.', array_filter( [
            'code'            => $code,
            'transmission_id' => $transmissionId,
            'mode'            => $this->client->environment()->mode,
            'debug_id'        => $debugId,
        ] ) );

        return WebhookResult::unverified( $code, $message );
    }
}
