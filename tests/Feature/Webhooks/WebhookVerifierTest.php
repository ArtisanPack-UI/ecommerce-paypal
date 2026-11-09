<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\WebhookVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const PAYPAL_CERT_URL = 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-test';

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'            => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret'        => 'client-secret',
        'artisanpack.ecommerce-paypal.webhook_id'           => 'WH-CURRENT',
        'artisanpack.ecommerce-paypal.webhook_verification' => 'local',
        'artisanpack.ecommerce-paypal.http.retries'         => 0,
    ] );

    Sleep::fake();
    Cache::flush();

    // Certificates take their start time from the real clock when they are
    // generated (key generation can take over a second on CI), so the test
    // clock is frozen a minute ahead: every certificate made during the
    // test is already valid at the frozen time.
    $this->travelTo( CarbonImmutable::now()->addMinute()->startOfSecond() );

    $this->paypal   = PayPalFake::fake();
    $this->identity = paypalSigningIdentity();

    servePayPalCertificate( $this->identity['pem'] );

    Http::fake( [
        'api.sandbox.paypal.com/*' => fn () => Http::response( ...$GLOBALS['paypal_test_certificate'] ),
        'api.paypal.com/*'         => fn () => Http::response( ...$GLOBALS['paypal_test_certificate'] ),
    ] );
} );

/**
 * A key pair and self-signed certificate standing in for PayPal's.
 *
 * @param  string  $commonName  Certificate CN.
 * @param  int     $days        Days the certificate is valid.
 *
 * @return array{key: OpenSSLAsymmetricKey, pem: string}
 */
function paypalSigningIdentity( string $commonName = 'messageverificationcerts.sandbox.paypal.com', int $days = 30 ): array
{
    $key = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
    $csr = openssl_csr_new( [ 'countryName' => 'US', 'organizationName' => 'PayPal, Inc.', 'commonName' => $commonName ], $key, [ 'digest_alg' => 'sha256' ] );
    $pem = '';

    openssl_x509_export( openssl_csr_sign( $csr, null, $key, $days, [ 'digest_alg' => 'sha256' ] ), $pem );

    return [ 'key' => $key, 'pem' => $pem ];
}

/**
 * Serves `$pem` (or an error) from PayPal's certificate hosts.
 *
 * @param  string  $pem     Certificate body.
 * @param  int     $status  HTTP status.
 *
 * @return void
 */
function servePayPalCertificate( string $pem, int $status = 200 ): void
{
    $GLOBALS['paypal_test_certificate'] = [ $pem, $status ];
}

/**
 * A webhook delivery signed the way PayPal signs it.
 *
 * @param  array{key: OpenSSLAsymmetricKey, pem: string}  $identity   Signing identity.
 * @param  array<string, string|null>                     $headers    Header overrides (null removes).
 * @param  string                                         $webhookId  Webhook id it is signed for.
 * @param  string|null                                    $sentBody   Body actually sent, when it differs from the signed one.
 *
 * @return Request
 */
function signedPayPalDelivery( array $identity, array $headers = [], string $webhookId = 'WH-CURRENT', ?string $sentBody = null ): Request
{
    $body = (string) json_encode( [
        'id'            => 'WH-EVENT-1',
        'event_version' => '1.0',
        'event_type'    => 'PAYMENT.CAPTURE.COMPLETED',
        'resource'      => [ 'id' => '3C679366HH908993F', 'amount' => [ 'currency_code' => 'USD', 'value' => '100.00' ] ],
    ] );
    $time = $headers['PAYPAL-TRANSMISSION-TIME'] ?? CarbonImmutable::now()->utc()->format( 'Y-m-d\TH:i:s\Z' );
    $id   = 'b2384410-f8d2-11e8-8f6a-1d7da0b5e6f1';

    openssl_sign( WebhookVerifier::signedString( $id, $time, $webhookId, $body ), $signature, $identity['key'], OPENSSL_ALGO_SHA256 );

    $values = array_merge( [
        'PAYPAL-TRANSMISSION-ID'   => $id,
        'PAYPAL-TRANSMISSION-TIME' => $time,
        'PAYPAL-TRANSMISSION-SIG'  => base64_encode( $signature ),
        'PAYPAL-CERT-URL'          => PAYPAL_CERT_URL,
        'PAYPAL-AUTH-ALGO'         => 'SHA256withRSA',
    ], $headers );
    $server = [ 'CONTENT_TYPE' => 'application/json' ];

    foreach ( $values as $name => $value ) {
        if ( null !== $value ) {
            $server[ 'HTTP_' . str_replace( '-', '_', $name ) ] = $value;
        }
    }

    return Request::create( '/ecommerce/webhooks/paypal', 'POST', [], [], [], $server, $sentBody ?? $body );
}

/**
 * Verifies `$request`.
 *
 * @param  Request  $request  Delivery.
 *
 * @return ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult
 */
function verifyPayPalDelivery( Request $request ): ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult
{
    return app( WebhookVerifier::class )->verify( $request );
}

/**
 * How many times a certificate was downloaded.
 *
 * @return int
 */
function paypalCertificateDownloads(): int
{
    return Http::recorded( fn ( ClientRequest $request ): bool => str_contains( $request->url(), '/v1/notifications/certs/' ) )->count();
}

describe( 'local verification', function (): void {
    it( 'verifies a delivery signed with PayPal\'s certificate', function (): void {
        $result = verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) );

        expect( $result->verified )->toBeTrue()
            ->and( $result->eventType )->toBe( 'PAYMENT.CAPTURE.COMPLETED' )
            ->and( $result->eventId )->toBe( 'WH-EVENT-1' )
            ->and( $result->payload['resource']['id'] )->toBe( '3C679366HH908993F' );
    } );

    it( 'caches the certificate until it expires', function (): void {
        verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) );
        verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) );

        expect( paypalCertificateDownloads() )->toBe( 1 );

        $this->travel( 31 )->days();

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'certificate_expired' )
            ->and( paypalCertificateDownloads() )->toBe( 2 );
    } );

    it( 'rejects a tampered body', function (): void {
        $result = verifyPayPalDelivery( signedPayPalDelivery( $this->identity, sentBody: '{"id":"WH-EVENT-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"id":"OTHER"}}' ) );

        expect( $result->verified )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'signature_mismatch' );
    } );

    it( 'rejects a delivery signed for another webhook id', function (): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, webhookId: 'WH-SOMEONE-ELSE' ) )->errorCode )->toBe( 'signature_mismatch' );
    } );

    it( 'rejects a signature made with another key', function (): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( paypalSigningIdentity() ) )->errorCode )->toBe( 'signature_mismatch' );
    } );

    it( 'rejects a signature that is not base64', function (): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-TRANSMISSION-SIG' => '%%%' ] ) )->errorCode )->toBe( 'signature_mismatch' );
    } );

    it( 'accepts deliveries for either webhook id during a rotation', function ( string $signedFor ): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => 'WH-OLD, WH-NEW' ] );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, webhookId: $signedFor ) )->verified )->toBeTrue();
    } )->with( [ 'WH-OLD', 'WH-NEW' ] );

    it( 'rejects an expired certificate', function (): void {
        $identity = paypalSigningIdentity( days: 1 );
        servePayPalCertificate( $identity['pem'] );
        $this->travel( 2 )->days();

        expect( verifyPayPalDelivery( signedPayPalDelivery( $identity ) )->errorCode )->toBe( 'certificate_expired' );
    } );

    it( 'rejects a certificate not issued to PayPal', function (): void {
        $identity = paypalSigningIdentity( 'webhooks.attacker.example' );
        servePayPalCertificate( $identity['pem'] );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $identity ) )->errorCode )->toBe( 'certificate_untrusted' );
    } );

    it( 'rejects an unreadable certificate', function (): void {
        servePayPalCertificate( 'not a certificate' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'certificate_untrusted' );
    } );

    it( 'rejects the delivery when the certificate cannot be downloaded', function (): void {
        servePayPalCertificate( 'Server error', 503 );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'certificate_unavailable' );
    } );
} );

describe( 'request checks', function (): void {
    it( 'rejects a delivery missing any signature header', function ( string $header ): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ $header => null ] ) )->errorCode )->toBe( 'missing_headers' );
    } )->with( array_values( WebhookVerifier::HEADERS ) );

    it( 'rejects any algorithm other than SHA256withRSA', function ( string $algorithm ): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-AUTH-ALGO' => $algorithm ] ) )->errorCode )->toBe( 'unsupported_algorithm' );
    } )->with( [ 'SHA1withRSA', 'sha256withrsa', 'none' ] );

    it( 'rejects a replayed, future, or unreadable transmission time', function ( ?int $offset ): void {
        $time = null === $offset ? 'yesterday-ish' : CarbonImmutable::now()->utc()->addSeconds( $offset )->format( 'Y-m-d\TH:i:s\Z' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-TRANSMISSION-TIME' => $time ] ) )->errorCode )->toBe( 'stale_transmission' );
    } )->with( [
        'replayed an hour later' => [ -3600 ],
        'just over 5 minutes'    => [ -301 ],
        'from the future'        => [ 360 ],
        'unreadable'             => [ null ],
    ] );

    it( 'accepts a transmission time within 5 minutes', function (): void {
        $time = CarbonImmutable::now()->utc()->subSeconds( 270 )->format( 'Y-m-d\TH:i:s\Z' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-TRANSMISSION-TIME' => $time ] ) )->verified )->toBeTrue();
    } );

    it( 'rejects certificate URLs off PayPal\'s certificate host, without fetching them', function ( string $url ): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-CERT-URL' => $url ] ) )->errorCode )->toBe( 'invalid_cert_url' )
            ->and( Http::recorded()->count() )->toBe( 0 );
    } )->with( [
        'attacker domain'    => [ 'https://attacker.example/v1/notifications/certs/CERT-1' ],
        'look-alike host'    => [ 'https://api.sandbox.paypal.com.attacker.example/v1/notifications/certs/CERT-1' ],
        'userinfo trick'     => [ 'https://api.sandbox.paypal.com@attacker.example/v1/notifications/certs/CERT-1' ],
        'plain http'         => [ 'http://api.sandbox.paypal.com/v1/notifications/certs/CERT-1' ],
        'live host, sandbox' => [ 'https://api.paypal.com/v1/notifications/certs/CERT-1' ],
        'other port'         => [ 'https://api.sandbox.paypal.com:8443/v1/notifications/certs/CERT-1' ],
        'other path'         => [ 'https://api.sandbox.paypal.com/v1/oauth2/token' ],
        'query string'       => [ PAYPAL_CERT_URL . '?redirect=https://attacker.example' ],
        'not a url'          => [ 'certificate' ],
    ] );

    it( 'uses the live certificate host in live mode', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.mode' => 'live' ] );
        $verifier = app( WebhookVerifier::class );

        expect( $verifier->isTrustedCertificateUrl( 'https://api.paypal.com/v1/notifications/certs/CERT-1' ) )->toBeTrue()
            ->and( $verifier->isTrustedCertificateUrl( PAYPAL_CERT_URL ) )->toBeFalse();
    } );

    it( 'rejects deliveries when no webhook id is configured', function ( mixed $configured ): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => $configured ] );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'webhook_id_missing' );
    } )->with( [ 'null' => [ null ], 'blank' => [ '' ], 'commas' => [ ' , ' ] ] );

    it( 'rejects a body that is not a PayPal event', function ( string $body ): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, sentBody: $body ) )->errorCode )->toBe( 'invalid_payload' );
    } )->with( [ 'not json' => [ 'hello' ], 'no event type' => [ '{"id":"WH-1"}' ], 'a list' => [ '[1,2]' ] ] );

    it( 'logs the rejection code but never the body', function (): void {
        $log = storage_path( 'logs/paypal-webhook-test.log' );
        @unlink( $log );
        config( [
            'logging.channels.paypal-webhook-test'          => [ 'driver' => 'single', 'path' => $log ],
            'artisanpack.ecommerce-paypal.logging.channel'  => 'paypal-webhook-test',
        ] );

        verifyPayPalDelivery( signedPayPalDelivery( $this->identity, sentBody: '{"id":"WH-1","event_type":"X","resource":{"payer":"secret-payer@example.com"}}' ) );

        $written = (string) file_get_contents( $log );
        @unlink( $log );

        expect( $written )->toContain( 'signature_mismatch' )
            ->toContain( 'b2384410-f8d2-11e8-8f6a-1d7da0b5e6f1' )
            ->not->toContain( 'secret-payer@example.com' );
    } );
} );

describe( 'postback verification', function (): void {
    beforeEach( function (): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_verification' => 'postback' ] );
    } );

    it( 'asks PayPal to verify the delivery', function (): void {
        $result = verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) );

        expect( $result->verified )->toBeTrue()
            ->and( $result->eventId )->toBe( 'WH-EVENT-1' )
            ->and( paypalCertificateDownloads() )->toBe( 0 );

        $this->paypal->assertSent( 'webhooks.verify_signature', fn ( ClientRequest $request ): bool => 'WH-CURRENT' === $request['webhook_id']
            && PAYPAL_CERT_URL === $request['cert_url']
            && 'SHA256withRSA' === $request['auth_algo']
            && 'WH-EVENT-1' === $request['webhook_event']['id'] );
    } );

    it( 'tries each webhook id during a rotation', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => 'WH-OLD,WH-NEW' ] );
        $this->paypal->respond( 'webhooks.verify_signature', 'webhooks/verify-signature-failure' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->verified )->toBeTrue();

        expect( array_map( fn ( ClientRequest $request ): string => $request['webhook_id'], $this->paypal->sent( 'webhooks.verify_signature' ) ) )->toBe( [ 'WH-OLD', 'WH-NEW' ] );
    } );

    it( 'rejects a delivery PayPal does not verify', function (): void {
        $this->paypal->respond( 'webhooks.verify_signature', 'webhooks/verify-signature-failure' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'signature_mismatch' );
    } );

    it( 'rejects the delivery when PayPal cannot be reached, so it is redelivered', function (): void {
        $this->paypal->error( 'webhooks.verify_signature', 'internal-server-error' );

        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'verification_unavailable' );
    } );

    it( 'still applies the header checks first', function (): void {
        expect( verifyPayPalDelivery( signedPayPalDelivery( $this->identity, [ 'PAYPAL-CERT-URL' => 'https://attacker.example/v1/notifications/certs/C' ] ) )->errorCode )->toBe( 'invalid_cert_url' );
        $this->paypal->assertNotSent( 'webhooks.verify_signature' );
    } );
} );

describe( 'gateways', function (): void {
    it( 'verifies deliveries to the paypal endpoint', function (): void {
        $result = app( PayPalWalletGateway::class )->handleWebhook( signedPayPalDelivery( $this->identity ) );

        expect( $result->verified )->toBeTrue()
            ->and( $result->eventType )->toBe( 'PAYMENT.CAPTURE.COMPLETED' );

        expect( app( PayPalWalletGateway::class )->handleWebhook( signedPayPalDelivery( $this->identity, [ 'PAYPAL-AUTH-ALGO' => 'none' ] ) )->verified )->toBeFalse();
    } );

    it( 'refuses signed deliveries at a sibling endpoint', function (): void {
        expect( app( PayPalCardGateway::class )->handleWebhook( signedPayPalDelivery( $this->identity ) )->errorCode )->toBe( 'use_paypal_endpoint' );
    } );
} );
