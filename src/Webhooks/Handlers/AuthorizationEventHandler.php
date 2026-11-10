<?php

/**
 * Records PayPal authorizations voided outside the store.
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
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalAuthorization;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Payments\AuthorizationLifecycle;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `PAYMENT.AUTHORIZATION.VOIDED` for orders that are already placed
 * (authorize-intent stores).
 *
 * The authorization is fetched from PayPal and its order is found through
 * the PayPal Order it belongs to (`supplementary_data.related_ids.order_id`).
 * When PayPal confirms it is `VOIDED` and it is the order's authorization
 * (`orders.meta.paypal.authorization.id`), and the order hadn't recorded the
 * void yet (a void the store made itself already has), this:
 *
 * - records `orders.meta.paypal.authorization.status = VOIDED`, so
 *   {@see AuthorizationLifecycle} stops reauthorizing and warning about it;
 * - writes a `paypal.authorization_voided` timeline entry;
 * - adds a system note telling staff the hold was released.
 *
 * The order and payment statuses aren't changed; that's store policy.
 *
 * Checkouts that aren't orders yet are released by the engine's reconciler
 * from the outcome the event mapper reports.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class AuthorizationEventHandler extends WebhookEventHandler
{
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
     * Handles an authorization event.
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
        $authorizationId = isset( $resource['id'] ) && is_scalar( $resource['id'] ) ? trim( (string) $resource['id'] ) : '';

        if ( 'PAYMENT.AUTHORIZATION.VOIDED' !== $type || '' === $authorizationId ) {
            return;
        }

        $authorization = $this->fetch( fn (): PayPalAuthorization => $this->client->payments()->getAuthorization( $authorizationId ), $eventId );

        if ( null === $authorization || $authorization->id() !== $authorizationId || 'VOIDED' !== $authorization->status() ) {
            return;
        }

        $paypalOrderId = $authorization->orderId();
        $order         = null === $paypalOrderId ? null : $this->orders->forPayPalOrder( $paypalOrderId );

        if ( null === $order || $authorizationId !== (string) PayPalOrderMeta::get( $order, 'authorization.id', '' ) ) {
            return;
        }

        $this->once( $order, $eventId, fn (): ?Closure => $this->voided( $order, $authorizationId ) );
    }

    /**
     * Records the void, unless the order already has.
     *
     * @since 1.0.0
     *
     * @param  Order   $order            Order.
     * @param  string  $authorizationId  Voided authorization id.
     *
     * @return Closure|null Nothing to run after commit.
     */
    protected function voided( Order $order, string $authorizationId ): ?Closure
    {
        // The order may have changed since it was found: check again under
        // the lock once() holds.
        $current = Order::query()->find( $order->getKey() );

        if ( null === $current
            || $authorizationId !== (string) PayPalOrderMeta::get( $current, 'authorization.id', '' )
            || 'VOIDED' === PayPalOrderMeta::get( $current, 'authorization.status' ) ) {
            return null;
        }

        PayPalOrderMeta::merge( $order, [ 'authorization' => [ 'status' => 'VOIDED' ] ] );

        $this->timeline( $order, 'paypal.authorization_voided', [ 'authorization_id' => $authorizationId ] );

        try {
            $this->notes->add( $order, __( 'PayPal voided authorization :id, so the held funds were released and can no longer be captured. Check the order before fulfilling it.', [
                'id' => $authorizationId,
            ] ) );
        } catch ( Throwable $exception ) {
            Log::channel( self::logChannel() )->warning( 'Could not add the PayPal voided-authorization note.', [
                'order_id' => $order->getKey(),
                'error'    => $exception->getMessage(),
            ] );
        }

        return null;
    }
}
