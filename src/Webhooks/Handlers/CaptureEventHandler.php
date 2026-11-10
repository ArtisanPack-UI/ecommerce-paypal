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
use ArtisanPackUI\Ecommerce\Services\PaymentOrchestrator;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalEventMapper;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `PAYMENT.CAPTURE.PENDING`, `.COMPLETED`, `.DENIED`, and `.DECLINED` for
 * orders that are already placed (spec §7.3, §8.2).
 *
 * A `PENDING` capture (an eCheck, a review hold) places the order as paid,
 * because the engine's `PaymentResult` has no pending state; the pending
 * state is kept in `orders.meta.paypal.capture_status`. This handler
 * settles it through {@see PayPalOrderMeta::transitionCapture()}, under the
 * order's lock, so events processed out of order can't take a settled
 * capture back to `PENDING`, and a settle that arrives before the gateway
 * recorded the capture isn't lost:
 *
 * - `PENDING` writes a `paypal.capture_pending` timeline entry with the
 *   reason (spec: timeline note only), when it recorded something new.
 * - `COMPLETED` for an open capture records `COMPLETED` and writes
 *   `paypal.capture_completed`.
 * - `DENIED` / `DECLINED` for an open capture records the status, writes
 *   `paypal.capture_denied`, adds a system note telling staff the money never
 *   arrived, and fails the order's payment through the engine's
 *   `PaymentOrchestrator::recordProviderPaymentFailure()`: `payment_status`
 *   becomes `failed`, with a `payment.capture_failed` timeline entry
 *   (`source: provider`), the `ap.ecommerce.payment.failed` hook and the
 *   `PaymentFailed` event. After commit it fires
 *   `ap.ecommerce.paypal.captureDenied`
 *   `(Order $order, string $captureId, string $status)`; a listener that
 *   throws is reported, not rethrown. The order status, stock, and digital
 *   deliverables aren't changed; that's store policy.
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
     * @param  PayPalClient         $client    API client.
     * @param  PayPalOrderLocator   $orders    Order lookup.
     * @param  OrderNoteService     $notes     Order notes.
     * @param  PaymentOrchestrator  $payments  Engine payments.
     */
    public function __construct(
        PayPalClient $client,
        PayPalOrderLocator $orders,
        protected OrderNoteService $notes,
        protected PaymentOrchestrator $payments,
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

        $status   = (string) $capture->status();
        $expected = match ( $type ) {
            'PAYMENT.CAPTURE.PENDING'                              => 'PENDING' === $status,
            'PAYMENT.CAPTURE.COMPLETED'                            => 'COMPLETED' === $status,
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'   => in_array( $status, PayPalEventMapper::FAILED_CAPTURE_STATES, true ),
            default                                                => false,
        };

        if ( ! $expected ) {
            return;
        }

        $this->once( $order, $eventId, fn (): ?Closure => match ( PayPalOrderMeta::transitionCapture( $order, $capture ) ) {
            'pending'   => $this->pending( $order, $capture ),
            'completed' => $this->completed( $order, $capture ),
            'denied'    => $this->denied( $order, $capture ),
            default     => null,
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
     * @return Closure|null Nothing to run after commit.
     */
    protected function pending( Order $order, PayPalCapture $capture ): ?Closure
    {
        $this->timeline( $order, 'paypal.capture_pending', [
            'capture_id' => $capture->id(),
            'reason'     => $capture->pendingReason(),
        ] );

        return null;
    }

    /**
     * Records a pending capture that completed.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return Closure|null Nothing to run after commit.
     */
    protected function completed( Order $order, PayPalCapture $capture ): ?Closure
    {
        $this->timeline( $order, 'paypal.capture_completed', [ 'capture_id' => $capture->id() ] );

        return null;
    }

    /**
     * Records a pending capture PayPal denied, and fails the order's
     * payment.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  Capture.
     *
     * @return Closure The hook to fire after commit.
     */
    protected function denied( Order $order, PayPalCapture $capture ): Closure
    {
        $status = (string) $capture->status();

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

        $this->payments->recordProviderPaymentFailure( $order, $capture->id(), 'capture_denied', __( 'PayPal denied the pending payment.' ) );

        return fn () => $this->fire( self::DENIED_HOOK, $order->fresh() ?? $order, $capture->id(), $status );
    }
}
