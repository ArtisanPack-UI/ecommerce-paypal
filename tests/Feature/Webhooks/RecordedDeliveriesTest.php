<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Webhooks\WebhookVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
| Real deliveries recorded from the PayPal sandbox by
| bin/record-webhook-deliveries.php (spec §12.3): PayPal's headers, the raw
| body, and the certificate it was signed with. Each must verify locally
| exactly as it arrived, and fail once its body is changed. Refresh them
| with the script when PayPal changes how it signs (docs/advanced/sandbox-tests.md).
*/

const PAYPAL_RECORDED_DELIVERIES = __DIR__ . '/../../Fixtures/signed-deliveries';

/**
 * The recorded deliveries, by file name.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function paypalRecordedDeliveries(): array
{
    $deliveries = [];

    foreach ( glob( PAYPAL_RECORDED_DELIVERIES . '/*.json' ) ?: [] as $file ) {
        $deliveries[ basename( $file, '.json' ) ] = [ json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ) ];
    }

    return $deliveries;
}

/**
 * The delivery as a request, with `$body` sent instead of the recorded one.
 *
 * @param  array<string, mixed>  $recording  Recording.
 * @param  string|null           $body       Body to send.
 *
 * @return Request
 */
function paypalRecordedRequest( array $recording, ?string $body = null ): Request
{
    $server = [ 'CONTENT_TYPE' => 'application/json' ];

    foreach ( $recording['headers'] as $name => $value ) {
        $server[ 'HTTP_' . str_replace( '-', '_', strtoupper( $name ) ) ] = $value;
    }

    return Request::create( '/ecommerce/webhooks/paypal', 'POST', [], [], [], $server, $body ?? $recording['body'] );
}

/**
 * Configures verification for `$recording` at the moment it was sent.
 *
 * @param  array<string, mixed>  $recording  Recording.
 *
 * @return void
 */
function paypalReplayAtTransmission( array $recording ): void
{
    config( [
        'artisanpack.ecommerce-paypal.mode'                 => $recording['mode'] ?? 'sandbox',
        'artisanpack.ecommerce-paypal.client_id'            => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret'        => 'client-secret',
        'artisanpack.ecommerce-paypal.webhook_id'           => $recording['webhook_id'],
        'artisanpack.ecommerce-paypal.webhook_verification' => 'local',
        'artisanpack.ecommerce-paypal.http.retries'         => 0,
    ] );

    Cache::flush();
    Http::preventStrayRequests();
    Http::fake( [ $recording['headers']['PAYPAL-CERT-URL'] => Http::response( $recording['certificate'] ) ] );

    test()->travelTo( CarbonImmutable::parse( $recording['headers']['PAYPAL-TRANSMISSION-TIME'] ) );
}

it( 'has the fixture directory the recorder writes to', function (): void {
    expect( is_dir( PAYPAL_RECORDED_DELIVERIES ) )->toBeTrue();
} );

it( 'verifies a delivery recorded from the sandbox exactly as PayPal sent it', function ( array $recording ): void {
    paypalReplayAtTransmission( $recording );

    $result = app( WebhookVerifier::class )->verify( paypalRecordedRequest( $recording ) );
    $event  = json_decode( $recording['body'], true, 512, JSON_THROW_ON_ERROR );

    expect( $result->verified )->toBeTrue( (string) $result->errorCode )
        ->and( $result->eventType )->toBe( $event['event_type'] )
        ->and( $result->eventId )->toBe( $event['id'] );
} )->with( fn (): array => paypalRecordedDeliveries() ?: [ 'none recorded yet' => [ [] ] ] )
    ->skip( fn (): bool => [] === paypalRecordedDeliveries(), 'No deliveries recorded yet. Record them with bin/record-webhook-deliveries.php.' );

it( 'rejects a recorded delivery whose body was changed', function ( array $recording ): void {
    paypalReplayAtTransmission( $recording );

    $event                     = json_decode( $recording['body'], true, 512, JSON_THROW_ON_ERROR );
    $event['resource']['id']   = 'TAMPERED';

    $result = app( WebhookVerifier::class )->verify( paypalRecordedRequest( $recording, (string) json_encode( $event ) ) );

    expect( $result->verified )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'signature_mismatch' );
} )->with( fn (): array => paypalRecordedDeliveries() ?: [ 'none recorded yet' => [ [] ] ] )
    ->skip( fn (): bool => [] === paypalRecordedDeliveries(), 'No deliveries recorded yet. Record them with bin/record-webhook-deliveries.php.' );
