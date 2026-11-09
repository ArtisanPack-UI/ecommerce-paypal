<?php

/**
 * PayPal Webhooks v1 endpoints.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api\Resources;

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalWebhook;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalWebhookEventList;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

/**
 * `/v1/notifications`: webhook subscriptions, signature verification, and
 * the event log.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class WebhooksApi
{
    /**
     * Creates the resource.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  Client.
     */
    public function __construct( private readonly PayPalClient $client )
    {
    }

    /**
     * Subscribes `$url` to `$eventTypes`.
     *
     * @since 1.0.0
     *
     * @param  string              $url         HTTPS URL PayPal posts to.
     * @param  array<int, string>  $eventTypes  Event names.
     * @param  string              $requestId   `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalWebhook
     */
    public function create( string $url, array $eventTypes, string $requestId ): PayPalWebhook
    {
        return PayPalWebhook::fromArray( $this->client->post( '/v1/notifications/webhooks', [
            'url'         => $url,
            'event_types' => self::eventTypes( $eventTypes ),
        ], $requestId ) );
    }

    /**
     * The app's webhooks.
     *
     * @since 1.0.0
     *
     * @throws PayPalApiException On failure.
     *
     * @return array<int, PayPalWebhook>
     */
    public function list(): array
    {
        $body = $this->client->get( '/v1/notifications/webhooks', [ 'anchor_type' => 'APPLICATION' ] );

        return array_map(
            static fn ( array $webhook ): PayPalWebhook => PayPalWebhook::fromArray( $webhook ),
            array_values( array_filter( (array) ( $body['webhooks'] ?? [] ), 'is_array' ) ),
        );
    }

    /**
     * Fetches a webhook.
     *
     * @since 1.0.0
     *
     * @param  string  $webhookId  Webhook id.
     *
     * @throws PayPalApiException On failure (a `404` when it doesn't exist).
     *
     * @return PayPalWebhook
     */
    public function get( string $webhookId ): PayPalWebhook
    {
        return PayPalWebhook::fromArray( $this->client->get( '/v1/notifications/webhooks/' . rawurlencode( $webhookId ) ) );
    }

    /**
     * Replaces a webhook's events (and, optionally, its URL).
     *
     * @since 1.0.0
     *
     * @param  string              $webhookId   Webhook id.
     * @param  array<int, string>  $eventTypes  Event names.
     * @param  string              $requestId   `PayPal-Request-Id`.
     * @param  string|null         $url         New URL, or null to keep it.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalWebhook
     */
    public function update( string $webhookId, array $eventTypes, string $requestId, ?string $url = null ): PayPalWebhook
    {
        $operations = [ [ 'op' => 'replace', 'path' => '/event_types', 'value' => self::eventTypes( $eventTypes ) ] ];

        if ( null !== $url ) {
            $operations[] = [ 'op' => 'replace', 'path' => '/url', 'value' => $url ];
        }

        return PayPalWebhook::fromArray( $this->client->patch( '/v1/notifications/webhooks/' . rawurlencode( $webhookId ), $operations, $requestId ) );
    }

    /**
     * Asks PayPal whether a delivery's signature is valid (postback
     * verification, spec §2.2 D7).
     *
     * @since 1.0.0
     *
     * @param  array{transmission_id: string, transmission_time: string, transmission_sig: string, cert_url: string, auth_algo: string}  $transmission  The `PAYPAL-*` header values.
     * @param  string                $webhookId  Webhook id the delivery should be for.
     * @param  array<string, mixed>  $event      The decoded delivery body.
     *
     * @throws PayPalApiException When the check itself failed.
     *
     * @return bool
     */
    public function verifySignature( array $transmission, string $webhookId, array $event ): bool
    {
        $body = $this->client->postReadOnly( '/v1/notifications/verify-webhook-signature', [
            'transmission_id'   => $transmission['transmission_id'],
            'transmission_time' => $transmission['transmission_time'],
            'transmission_sig'  => $transmission['transmission_sig'],
            'cert_url'          => $transmission['cert_url'],
            'auth_algo'         => $transmission['auth_algo'],
            'webhook_id'        => $webhookId,
            'webhook_event'     => $event,
        ] );

        return 'SUCCESS' === strtoupper( (string) ( $body['verification_status'] ?? '' ) );
    }

    /**
     * Lists recent webhook events.
     *
     * @since 1.0.0
     *
     * @param  string|null  $eventType  Only this event name.
     * @param  string|null  $startTime  ISO 8601 lower bound.
     * @param  string|null  $endTime    ISO 8601 upper bound.
     * @param  int          $pageSize   Events per page (1–300).
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalWebhookEventList
     */
    public function events( ?string $eventType = null, ?string $startTime = null, ?string $endTime = null, int $pageSize = 50 ): PayPalWebhookEventList
    {
        return PayPalWebhookEventList::fromArray( $this->client->get( '/v1/notifications/webhooks-events', array_filter( [
            'event_type' => $eventType,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'page_size'  => min( 300, max( 1, $pageSize ) ),
        ], static fn ( mixed $value ): bool => null !== $value ) ) );
    }

    /**
     * Shapes event names for the API.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $eventTypes  Event names.
     *
     * @return array<int, array{name: string}>
     */
    private static function eventTypes( array $eventTypes ): array
    {
        return array_map( static fn ( string $name ): array => [ 'name' => $name ], array_values( array_unique( $eventTypes ) ) );
    }
}
