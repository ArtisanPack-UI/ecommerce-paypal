<?php

/**
 * Shared plumbing for PayPal webhook event handlers.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks\Handlers;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Base for the handlers {@see \ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor}
 * routes events to.
 *
 * Handlers act on resources re-fetched from PayPal's API, never on the
 * webhook body alone: PayPal signs `crc32(body)`, which doesn't protect the
 * body's contents. A fetch that may succeed later (timeouts, `429`, `5xx`)
 * throws, so the queued job retries it; any other API error is logged and
 * the event is dropped.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class WebhookEventHandler
{
    /**
     * How many processed event ids an order remembers.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const REMEMBERED_EVENTS = 50;

    /**
     * Creates the handler.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient        $client   API client.
     * @param  PayPalOrderLocator  $orders   Order lookup.
     */
    public function __construct(
        protected PayPalClient $client,
        protected PayPalOrderLocator $orders,
    ) {
    }

    /**
     * Handles one event.
     *
     * @since 1.0.0
     *
     * @param  string                $type      Event type.
     * @param  array<string, mixed>  $resource  Event resource (untrusted).
     * @param  string                $eventId   Event id, for logs.
     *
     * @throws PayPalApiException When PayPal can't be reached (the job retries).
     *
     * @return void
     */
    abstract public function handle( string $type, array $resource, string $eventId ): void;

    /**
     * Runs `$fetch` against PayPal: rethrows retryable failures, logs and
     * returns null for the rest.
     *
     * @since 1.0.0
     *
     * @template TResult
     *
     * @param  callable(): TResult  $fetch    API call.
     * @param  string               $eventId  Event id, for logs.
     *
     * @throws PayPalApiException When the failure is retryable.
     *
     * @return TResult|null
     */
    protected function fetch( callable $fetch, string $eventId ): mixed
    {
        try {
            return $fetch();
        } catch ( PayPalApiException $exception ) {
            if ( $exception->isRetryable() ) {
                throw $exception;
            }

            Log::channel( self::logChannel() )->warning( 'Could not load the PayPal resource a webhook is about; the event was skipped.', [
                'event_id' => $eventId,
                ...$exception->context(),
            ] );

            return null;
        }
    }

    /**
     * The order paid with capture `$captureId`.
     *
     * Looked up by the capture id the order recorded, and otherwise by the
     * PayPal Order PayPal says the capture belongs to (`$capture`, fetched
     * when not passed).
     *
     * @since 1.0.0
     *
     * @param  string              $captureId  Capture id confirmed with PayPal.
     * @param  string              $eventId    Event id, for logs.
     * @param  PayPalCapture|null  $capture    The capture, when already fetched.
     *
     * @throws PayPalApiException When PayPal can't be reached.
     *
     * @return Order|null
     */
    protected function orderForCapture( string $captureId, string $eventId, ?PayPalCapture $capture = null ): ?Order
    {
        $order = $this->orders->forCapture( $captureId );

        if ( null !== $order ) {
            return $order;
        }

        $capture ??= $this->fetch( fn (): PayPalCapture => $this->client->payments()->getCapture( $captureId ), $eventId );
        $orderId   = $capture?->orderId();

        return null === $orderId ? null : $this->orders->forCapture( $captureId, $orderId );
    }

    /**
     * Runs `$work` for event `$eventId` on `$order` unless the order already
     * processed that event.
     *
     * The engine deduplicates deliveries, but it releases its claim when a
     * later `webhook_received` listener throws, and PayPal then redelivers.
     * The ids are kept in `orders.meta.paypal.webhook_events` (the latest
     * {@see self::REMEMBERED_EVENTS}, checked and written under the order's
     * lock), so a redelivery doesn't add a second note or timeline entry.
     * When `$work` throws, the id is forgotten again, so the job's retry (or
     * PayPal's redelivery) does the work instead of skipping it.
     *
     * @since 1.0.0
     *
     * @param  Order     $order    Order.
     * @param  string    $eventId  Event id (empty always runs).
     * @param  callable  $work     The event's side effects.
     *
     * @throws Throwable Whatever `$work` throws.
     *
     * @return void
     */
    protected function once( Order $order, string $eventId, callable $work ): void
    {
        if ( '' !== $eventId && ! PayPalOrderMeta::rememberEvent( $order, $eventId, self::REMEMBERED_EVENTS ) ) {
            return;
        }

        try {
            $work();
        } catch ( Throwable $exception ) {
            if ( '' !== $eventId ) {
                PayPalOrderMeta::forgetEvent( $order, $eventId );
            }

            throw $exception;
        }
    }

    /**
     * Writes an order timeline entry.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order.
     * @param  string                $type     Event type (`paypal.capture_pending`, …).
     * @param  array<string, mixed>  $payload  Payload.
     *
     * @return void
     */
    protected function timeline( Order $order, string $type, array $payload ): void
    {
        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->getKey(),
            'actor_user_id' => null,
            'event_type'    => $type,
            'payload'       => array_filter( $payload, static fn ( mixed $value ): bool => null !== $value && '' !== $value ),
        ] );
    }

    /**
     * The configured log channel, or null for the default.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected static function logChannel(): ?string
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return is_string( $channel ) && '' !== $channel ? $channel : null;
    }
}
