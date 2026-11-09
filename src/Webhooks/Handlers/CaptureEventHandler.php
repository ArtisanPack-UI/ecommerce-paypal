<?php

/**
 * Settles pending PayPal captures from their webhooks.
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
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalEventMapper;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `PAYMENT.CAPTURE.PENDING`, `.COMPLETED`, `.DENIED`, and `.DECLINED` for
 * orders that are already placed (spec §7.3, §8.2).
 *
 * A `PENDING` capture (an eCheck, a review hold) places the order as paid,
 * because the engine's `PaymentResult` has no pending state; the pending
 * state is kept in `orders.meta.paypal.capture_status`. This handler
 * settles it:
 *
 * - `PENDING` writes a `paypal.capture_pending` timeline entry with the
 *   reason (spec: timeline note only).
 * - `COMPLETED` for a pending capture records `COMPLETED` and writes
 *   `paypal.capture_completed`.
 * - `DENIED` / `DECLINED` for a pending capture records the status, writes
 *   `paypal.capture_denied`, adds a system note telling staff the money never
 *   arrived, and fires `ap.ecommerce.paypal.captureDenied`
 *   `(Order $order, string $captureId, string $status)`. The order status
 *   isn't changed; that's store policy.
 *
 * Checkouts that aren't orders yet are settled by the engine's reconciler
 * from the outcome {@see PayPalEventMapper} reports.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class CaptureEventHandler extends WebhookEventHandler
{
    /**
     * Fired when a pending capture on a placed order is denied.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DENIED_HOOK = 'ap.ecommerce.paypal.captureDenied';

    /**
     * Creates the handler.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient        $client  API client.
     * @param  PayPalOrderLocator  $orders  Order lookup.
     * @param  OrderNoteService    $notes   Order notes.
     */
    public function __construct(
        PayPalClient $client,
        PayPalOrderLocator $orders,
        protected OrderNoteService $notes,
    ) {
        parent::__construct( $client, $orders );
    }

    /**
     * Handles a capture event.
     *
     * @since 1.0.0
     *
     * @param  string                $type      Event type.
     * @param  array<string, mixed>  $resource  Event resource (untrusted).
     * @param  string                $eventId   Event id.
     *
     * @return void
     */
    public function handle( string $type, array $resource, string $eventId ): void
    {
        $captureId = isset( $resource['id'] ) && is_scalar( $resource['id'] ) ? trim( (string) $resource['id'] ) : '';

        if ( '' === $captureId ) {
            return;
        }

        $capture = $this->fetch( fn (): PayPalCapture => $this->client->payments()->getCapture( $captureId ), $eventId );

        if ( null === $capture || $capture->id() !== $captureId ) {
            return;
        }

        $order = $this->orderForCapture( $captureId, $eventId, $capture );

        if ( null === $order ) {
            return;
        }

        $status  = (string) $capture->status();
        $failure = in_array( $type, [ 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' ], true );

        $this->once( $order, $eventId, fn () => match ( true ) {
            'PAYMENT.CAPTURE.PENDING' === $type && 'PENDING' === $status                    => $this->pending( $order, $capture ),
            'PAYMENT.CAPTURE.COMPLETED' === $type && 'COMPLETED' === $status                => $this->completed( $order, $capture ),
            $failure && in_array( $status, PayPalEventMapper::FAILED_CAPTURE_STATES, true ) => $this->denied( $order, $capture ),
            default                                                                         => null,
        } );
    }

    /**
     * Records a pending capture.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return void
     */
    protected function pending( Order $order, PayPalCapture $capture ): void
    {
        PayPalOrderMeta::merge( $order, [
            'capture_id'     => $capture->id(),
            'capture_status' => 'PENDING',
            'pending_reason' => $capture->pendingReason(),
        ] );

        $this->timeline( $order, 'paypal.capture_pending', [
            'capture_id' => $capture->id(),
            'reason'     => $capture->pendingReason(),
        ] );
    }

    /**
     * Settles a pending capture that completed.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return void
     */
    protected function completed( Order $order, PayPalCapture $capture ): void
    {
        if ( ! $this->isPendingCapture( $order, $capture ) ) {
            return;
        }

        PayPalOrderMeta::merge( $order, [ 'capture_status' => 'COMPLETED', 'pending_reason' => null ] );

        $this->timeline( $order, 'paypal.capture_completed', [ 'capture_id' => $capture->id() ] );
    }

    /**
     * Records a pending capture PayPal denied.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return void
     */
    protected function denied( Order $order, PayPalCapture $capture ): void
    {
        if ( ! $this->isPendingCapture( $order, $capture ) ) {
            return;
        }

        $status = (string) $capture->status();

        PayPalOrderMeta::merge( $order, [ 'capture_status' => $status, 'pending_reason' => null ] );

        $this->timeline( $order, 'paypal.capture_denied', [ 'capture_id' => $capture->id(), 'status' => $status ] );

        try {
            $this->notes->add( $order, __( 'PayPal denied pending payment :capture, so the money for this order never arrived. Check the order before fulfilling it.', [
                'capture' => $capture->id(),
            ] ) );
        } catch ( Throwable $exception ) {
            Log::channel( self::logChannel() )->warning( 'Could not add the PayPal denied-capture note.', [
                'order_id' => $order->getKey(),
                'error'    => $exception->getMessage(),
            ] );
        }

        doAction( self::DENIED_HOOK, $order, $capture->id(), $status );
    }

    /**
     * Whether `$capture` is the order's capture and still recorded as pending.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return bool
     */
    protected function isPendingCapture( Order $order, PayPalCapture $capture ): bool
    {
        return $capture->id() === PayPalOrderMeta::get( $order, 'capture_id' )
            && 'PENDING' === PayPalOrderMeta::get( $order, 'capture_status' );
    }
}
