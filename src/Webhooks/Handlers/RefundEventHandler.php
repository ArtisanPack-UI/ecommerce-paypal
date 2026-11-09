<?php

/**
 * Records refunds made in PayPal from their webhooks.
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

use ArtisanPackUI\Ecommerce\Exceptions\RefundNotAllowedException;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Services\RefundService;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalRefund;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * `PAYMENT.CAPTURE.REFUNDED` and `PAYMENT.CAPTURE.REVERSED` (spec §8.2).
 *
 * The refund is fetched from PayPal, then recorded on the order through
 * the engine's `RefundService::recordProviderRefund()`, which doesn't call
 * PayPal again. That covers refunds made in the PayPal dashboard and
 * reversals (chargebacks PayPal settled against the merchant).
 *
 * Nothing is recorded when the refund isn't `COMPLETED`, or when the engine
 * already has it: a refund with this PayPal refund id, or the refund its
 * `custom_id` names (one the store issued, which settles through
 * `RefundService::issue()`, even while it is still pending). The engine
 * checks both under the order's lock.
 *
 * A reversal also writes a `paypal.capture_reversed` timeline entry. When
 * the engine refuses the refund (an over-refund, a currency mismatch), a
 * `paypal.refund_not_recorded` entry is written for staff to reconcile.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class RefundEventHandler extends WebhookEventHandler
{
    /**
     * Creates the handler.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient        $client   API client.
     * @param  PayPalOrderLocator  $orders   Order lookup.
     * @param  RefundService       $refunds  Engine refunds.
     */
    public function __construct(
        PayPalClient $client,
        PayPalOrderLocator $orders,
        protected RefundService $refunds,
    ) {
        parent::__construct( $client, $orders );
    }

    /**
     * Handles a refund or reversal event.
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
        $refundId = isset( $resource['id'] ) && is_scalar( $resource['id'] ) ? trim( (string) $resource['id'] ) : '';

        if ( '' === $refundId ) {
            return;
        }

        $refund = $this->fetch( fn (): PayPalRefund => $this->client->payments()->getRefund( $refundId ), $eventId );

        if ( null === $refund || $refund->id() !== $refundId || null === $refund->captureId() ) {
            return;
        }

        $order = $this->orderForCapture( $refund->captureId(), $eventId );

        if ( null === $order ) {
            Log::channel( self::logChannel() )->info( 'A PayPal refund webhook matched no order.', [
                'event_id'   => $eventId,
                'refund_id'  => $refundId,
                'capture_id' => $refund->captureId(),
            ] );

            return;
        }

        $this->once( $order, $eventId, fn () => $this->apply( $type, $order, $refund ) );
    }

    /**
     * The event's side effects: the reversal entry and the refund.
     *
     * @since 1.0.0
     *
     * @param  string        $type    Event type.
     * @param  Order         $order   Order.
     * @param  PayPalRefund  $refund  Refund, as PayPal returned it.
     *
     * @return void
     */
    protected function apply( string $type, Order $order, PayPalRefund $refund ): void
    {
        $refundId = $refund->id();

        if ( 'PAYMENT.CAPTURE.REVERSED' === $type ) {
            $this->timeline( $order, 'paypal.capture_reversed', [
                'capture_id' => $refund->captureId(),
                'refund_id'  => $refundId,
                'amount'     => $refund->amount()['value'] ?? null,
                'currency'   => $refund->amount()['currency_code'] ?? null,
                'reason'     => $refund->get( 'note_to_payer' ),
            ] );
        }

        if ( 'COMPLETED' !== $refund->status() ) {
            return;
        }

        $this->record( $order, $refund );
    }

    /**
     * Records `$refund` on the order.
     *
     * @since 1.0.0
     *
     * @param  Order         $order   Order.
     * @param  PayPalRefund  $refund  Completed refund.
     *
     * @return void
     */
    protected function record( Order $order, PayPalRefund $refund ): void
    {
        $note = $refund->get( 'note_to_payer' );

        try {
            if ( null === $refund->amount() ) {
                throw new InvalidArgumentException( 'PayPal returned the refund without an amount.' );
            }

            $recorded = $this->refunds->recordProviderRefund(
                $order,
                PayPalMoney::fromPayPal( $refund->amount() ),
                $refund->id(),
                is_string( $note ) ? $note : null,
                $refund->customId(),
            );

            // The refund the store issued was settled failed (the gateway
            // call errored), but PayPal completed it: staff must reconcile.
            if ( ! $recorded->wasRecentlyCreated && Refund::STATUS_FAILED === $recorded->status ) {
                throw new RefundNotAllowedException( __( 'PayPal completed refund :refund, but the store recorded it as failed.', [ 'refund' => $refund->id() ] ) );
            }
        } catch ( RefundNotAllowedException|InvalidArgumentException $exception ) {
            Log::channel( self::logChannel() )->warning( 'A PayPal refund could not be recorded on its order.', [
                'order_id'  => $order->getKey(),
                'refund_id' => $refund->id(),
                'error'     => $exception->getMessage(),
            ] );

            $this->timeline( $order, 'paypal.refund_not_recorded', [
                'refund_id' => $refund->id(),
                'amount'    => $refund->amount()['value'] ?? null,
                'currency'  => $refund->amount()['currency_code'] ?? null,
                'error'     => $exception->getMessage(),
            ] );
        }
    }
}
