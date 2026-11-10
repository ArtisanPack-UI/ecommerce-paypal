<?php

/**
 * Records signed PayPal sandbox webhook deliveries as test fixtures.
 *
 * A router script for PHP's built-in server. It saves every delivery
 * PayPal posts (headers, raw body, and the signing certificate) to
 * `tests/Fixtures/signed-deliveries/{EVENT_TYPE}.json`, after checking the
 * signature the same way `WebhookVerifier` does, so only real, verifiable
 * deliveries are kept. `tests/Feature/Webhooks/RecordedDeliveriesTest.php`
 * verifies every recording.
 *
 * Usage (see docs/advanced/sandbox-tests.md):
 *
 *     PAYPAL_SANDBOX_WEBHOOK_ID=WH-... php -S 127.0.0.1:8787 bin/record-webhook-deliveries.php
 *     cloudflared tunnel --url http://127.0.0.1:8787    # or ngrok, herd share, …
 *
 * Point the sandbox webhook at the tunnel's HTTPS URL, run
 * `composer test:sandbox` (or pay in a sandbox checkout), then stop the
 * server and commit the new fixtures.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

if ( 'cli-server' !== PHP_SAPI ) {
    fwrite( STDERR, "Run this with PHP's built-in server: php -S 127.0.0.1:8787 bin/record-webhook-deliveries.php\n" );

    return;
}

const RECORDER_CERT_HOST = 'api.sandbox.paypal.com';
const RECORDER_HEADERS   = [ 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-TIME', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-CERT-URL', 'PAYPAL-AUTH-ALGO' ];

/**
 * Sends a plain-text response and logs it to the server console.
 *
 * @param  int     $status   HTTP status.
 * @param  string  $message  Message.
 *
 * @return void
 */
function recorderRespond( int $status, string $message ): void
{
    http_response_code( $status );
    header( 'Content-Type: text/plain' );
    echo $message;
    error_log( '[' . $status . '] ' . $message );
}

$webhookId = trim( (string) getenv( 'PAYPAL_SANDBOX_WEBHOOK_ID' ) );

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
    recorderRespond( 200, 'PayPal delivery recorder is running. Point the sandbox webhook here.' );

    return;
}

if ( '' === $webhookId ) {
    recorderRespond( 500, 'Set PAYPAL_SANDBOX_WEBHOOK_ID to the id of the webhook PayPal is delivering for.' );

    return;
}

$body    = (string) file_get_contents( 'php://input' );
$headers = [];

foreach ( RECORDER_HEADERS as $name ) {
    $value = $_SERVER[ 'HTTP_' . str_replace( '-', '_', $name ) ] ?? null;

    if ( ! is_string( $value ) || '' === $value ) {
        recorderRespond( 400, 'Missing ' . $name . '; not a PayPal delivery.' );

        return;
    }

    $headers[ $name ] = $value;
}

// WebhookVerifier only accepts this algorithm, so a recording made with any
// other would fail on replay.
if ( 'SHA256withRSA' !== $headers['PAYPAL-AUTH-ALGO'] ) {
    recorderRespond( 400, 'Unsupported signing algorithm: ' . $headers['PAYPAL-AUTH-ALGO'] );

    return;
}

$certUrl = parse_url( $headers['PAYPAL-CERT-URL'] );

if ( ! is_array( $certUrl )
    || 'https' !== ( $certUrl['scheme'] ?? '' )
    || RECORDER_CERT_HOST !== strtolower( (string) ( $certUrl['host'] ?? '' ) )
    || isset( $certUrl['user'] ) || isset( $certUrl['pass'] ) || isset( $certUrl['query'] ) || isset( $certUrl['fragment'] )
    || ( isset( $certUrl['port'] ) && 443 !== (int) $certUrl['port'] )
    || ! str_starts_with( (string) ( $certUrl['path'] ?? '' ), '/v1/notifications/certs/' ) ) {
    recorderRespond( 400, 'Untrusted certificate URL: ' . $headers['PAYPAL-CERT-URL'] );

    return;
}

$certificate = @file_get_contents( $headers['PAYPAL-CERT-URL'] );

if ( ! is_string( $certificate ) || '' === $certificate ) {
    recorderRespond( 502, 'Could not download the certificate.' );

    return;
}

$signed   = implode( '|', [ $headers['PAYPAL-TRANSMISSION-ID'], $headers['PAYPAL-TRANSMISSION-TIME'], $webhookId, sprintf( '%u', crc32( $body ) ) ] );
$verified = 1 === openssl_verify( $signed, (string) base64_decode( $headers['PAYPAL-TRANSMISSION-SIG'], true ), $certificate, OPENSSL_ALGO_SHA256 );

if ( ! $verified ) {
    recorderRespond( 400, 'The signature does not verify for webhook ' . $webhookId . '; nothing recorded. Is PAYPAL_SANDBOX_WEBHOOK_ID right?' );

    return;
}

$event = json_decode( $body, true );
$type  = is_array( $event ) && is_string( $event['event_type'] ?? null ) ? $event['event_type'] : 'UNKNOWN';
$dir   = dirname( __DIR__ ) . '/tests/Fixtures/signed-deliveries';

if ( ! is_dir( $dir ) ) {
    mkdir( $dir, 0755, true );
}

$file = $dir . '/' . preg_replace( '/[^A-Z0-9.\-]/', '_', strtoupper( $type ) ) . '.json';

file_put_contents( $file, json_encode( [
    'recorded_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
    'mode'        => 'sandbox',
    'webhook_id'  => $webhookId,
    'headers'     => $headers,
    'body'        => $body,
    'certificate' => $certificate,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

recorderRespond( 200, 'Recorded ' . $type . ' to ' . basename( $file ) );
