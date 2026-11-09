<?php

/**
 * Finds the engine order a PayPal payment belongs to.
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

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;

/**
 * Looks up placed orders paid through any of the four PayPal gateways
 * (spec §6.1), by capture id or by the PayPal Order id the engine stores
 * as `payment_reference`.
 *
 * The capture id is in `orders.meta.paypal.capture_id` when the gateway
 * captured the payment, and in the engine's `meta.payment.capture_reference`
 * either way (an Order PayPal already reports as captured is recorded by
 * the engine without calling the gateway).
 *
 * Callers pass ids they have confirmed with PayPal's API, never ids taken
 * from a webhook body alone (PayPal signs `crc32(body)`, which doesn't
 * protect the body's contents).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalOrderLocator
{
    /**
     * The gateway keys PayPal orders are placed under.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function gatewayKeys(): array
    {
        return array_map(
            static fn ( string $gateway ): string => $gateway::KEY,
            EcommercePayPalServiceProvider::GATEWAYS,
        );
    }

    /**
     * The order whose payment is capture `$captureId`, or, failing that, the
     * order placed with PayPal Order `$paypalOrderId`.
     *
     * The PayPal Order match is skipped when that order already recorded a
     * different capture, so a capture never attaches to the wrong order.
     *
     * @since 1.0.0
     *
     * @param  string|null  $captureId      Capture id.
     * @param  string|null  $paypalOrderId  PayPal Order id.
     *
     * @return Order|null
     */
    public function forCapture( ?string $captureId, ?string $paypalOrderId = null ): ?Order
    {
        $captureId     = null === $captureId ? '' : trim( $captureId );
        $paypalOrderId = null === $paypalOrderId ? '' : trim( $paypalOrderId );

        if ( '' !== $captureId ) {
            /** @var Order|null $order */
            $order = Order::query()
                ->whereIn( 'payment_gateway_key', self::gatewayKeys() )
                ->where( static function ( $query ) use ( $captureId ): void {
                    $query->where( 'meta->paypal->capture_id', $captureId )
                        ->orWhere( 'meta->payment->capture_reference', $captureId );
                } )
                ->orderByDesc( 'id' )
                ->first();

            if ( null !== $order ) {
                return $order;
            }
        }

        if ( '' === $paypalOrderId ) {
            return null;
        }

        $order = $this->forPayPalOrder( $paypalOrderId );

        if ( null === $order || '' === $captureId ) {
            return $order;
        }

        $meta     = $order->getAttribute( 'meta' );
        $recorded = data_get( $meta, 'paypal.capture_id' ) ?? data_get( $meta, 'payment.capture_reference' );

        return null === $recorded || '' === $recorded || $captureId === (string) $recorded ? $order : null;
    }

    /**
     * The order placed with PayPal Order `$paypalOrderId`, or null.
     *
     * @since 1.0.0
     *
     * @param  string  $paypalOrderId  PayPal Order id.
     *
     * @return Order|null
     */
    public function forPayPalOrder( string $paypalOrderId ): ?Order
    {
        if ( '' === trim( $paypalOrderId ) ) {
            return null;
        }

        /** @var Order|null */
        return Order::query()
            ->whereIn( 'payment_gateway_key', self::gatewayKeys() )
            ->where( 'payment_reference', $paypalOrderId )
            ->orderByDesc( 'id' )
            ->first();
    }
}
