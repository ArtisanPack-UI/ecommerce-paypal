<?php

/**
 * Routes deduplicated PayPal webhook events to their handlers.
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

use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Jobs\ProcessPayPalWebhook;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\CaptureEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\DisputeEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\RefundEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\WebhookEventHandler;

/**
 * The side effects of PayPal events, beyond the payment outcome the engine
 * reconciles: settling pending captures, recording refunds made in PayPal,
 * and recording disputes.
 *
 * It runs from {@see ProcessPayPalWebhook}, which is queued from the
 * engine's `ap.ecommerce.gateway.paypal.webhook_received` action. That
 * action fires only for verified deliveries the engine hasn't seen before,
 * so a redelivered event is never processed twice.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalWebhookProcessor
{
    /**
     * Handler class by event type.
     *
     * @since 1.0.0
     *
     * @var array<string, class-string<WebhookEventHandler>>
     */
    public const HANDLERS = [
        'PAYMENT.CAPTURE.PENDING'   => CaptureEventHandler::class,
        'PAYMENT.CAPTURE.COMPLETED' => CaptureEventHandler::class,
        'PAYMENT.CAPTURE.DENIED'    => CaptureEventHandler::class,
        'PAYMENT.CAPTURE.DECLINED'  => CaptureEventHandler::class,
        'PAYMENT.CAPTURE.REFUNDED'  => RefundEventHandler::class,
        'PAYMENT.CAPTURE.REVERSED'  => RefundEventHandler::class,
        'CUSTOMER.DISPUTE.CREATED'  => DisputeEventHandler::class,
        'CUSTOMER.DISPUTE.UPDATED'  => DisputeEventHandler::class,
        'CUSTOMER.DISPUTE.RESOLVED' => DisputeEventHandler::class,
    ];

    /**
     * Whether events of `$type` have side effects to process.
     *
     * @since 1.0.0
     *
     * @param  string|null  $type  Event type.
     *
     * @return bool
     */
    public static function handles( ?string $type ): bool
    {
        return null !== $type && isset( self::HANDLERS[ $type ] );
    }

    /**
     * The parts of a verified event the handlers use: its id, type, and the
     * resource's id (`id`, or `dispute_id` for disputes). Everything else is
     * re-fetched from PayPal, so the payer's personal data never goes into
     * the queue's `jobs` / `failed_jobs` tables.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $event  The event as PayPal sent it.
     *
     * @return array{id: string, event_type: string, resource: array<string, string>}
     */
    public static function minimal( array $event ): array
    {
        $resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : [];

        return [
            'id'         => isset( $event['id'] ) && is_scalar( $event['id'] ) ? (string) $event['id'] : '',
            'event_type' => isset( $event['event_type'] ) && is_string( $event['event_type'] ) ? $event['event_type'] : '',
            'resource'   => array_map(
                'strval',
                array_filter(
                    array_intersect_key( $resource, array_flip( [ 'id', 'dispute_id' ] ) ),
                    static fn ( mixed $value ): bool => is_scalar( $value ),
                ),
            ),
        ];
    }

    /**
     * Processes one verified event.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $event  The event as PayPal sent it.
     *
     * @throws PayPalApiException When PayPal can't be reached (the job retries).
     *
     * @return void
     */
    public function process( array $event ): void
    {
        $type = isset( $event['event_type'] ) && is_string( $event['event_type'] ) ? $event['event_type'] : null;

        if ( ! self::handles( $type ) ) {
            return;
        }

        $resource = is_array( $event['resource'] ?? null ) ? $event['resource'] : [];
        $eventId  = isset( $event['id'] ) && is_scalar( $event['id'] ) ? (string) $event['id'] : '';

        /** @var WebhookEventHandler $handler */
        $handler = app( self::HANDLERS[ $type ] );

        $handler->handle( (string) $type, $resource, $eventId );
    }
}
