<?php

/**
 * Reads and writes `orders.meta.paypal`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Support;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use Illuminate\Support\Facades\DB;

/**
 * The `orders.meta.paypal` namespace (spec §3): the PayPal Order, capture,
 * and authorization ids, payer details, and the authorization lifecycle.
 *
 * Writes merge into the stored row under a row lock, so they never clobber
 * meta the engine wrote in between (the finalize claim, the capture
 * reference). An order that isn't saved yet is updated in memory only.
 *
 * A capture that settled (see {@see self::SETTLED_CAPTURE_STATES}) never
 * goes back to `PENDING`: a merge that would record `PENDING` for the
 * capture the order already settled keeps the settled status.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalOrderMeta
{
    /**
     * The meta key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'paypal';

    /**
     * Capture statuses a capture never leaves for `PENDING`.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SETTLED_CAPTURE_STATES = [ 'COMPLETED', 'DECLINED', 'DENIED', 'FAILED', 'PARTIALLY_REFUNDED', 'REFUNDED' ];

    /**
     * Capture statuses a pending capture can settle to.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const FINAL_CAPTURE_STATES = [ 'COMPLETED', 'DECLINED', 'DENIED', 'FAILED' ];

    /**
     * A value from `orders.meta.paypal` by dot path, or `$default`.
     *
     * @since 1.0.0
     *
     * @param  Order   $order    Order.
     * @param  string  $path     Dot path inside the namespace (`authorization.id`).
     * @param  mixed   $default  Fallback.
     *
     * @return mixed
     */
    public static function get( Order $order, string $path, mixed $default = null ): mixed
    {
        return data_get( self::all( $order ), $path, $default );
    }

    /**
     * The whole `orders.meta.paypal` namespace.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array<string, mixed>
     */
    public static function all( Order $order ): array
    {
        $meta = (array) ( $order->getAttribute( 'meta' ) ?? [] );

        return is_array( $meta[ self::KEY ] ?? null ) ? $meta[ self::KEY ] : [];
    }

    /**
     * Whether `orders.meta.paypal` describes `$paypalOrderId`: it names that
     * Order, or names none (recorded before the Order id was known).
     *
     * @since 1.0.0
     *
     * @param  Order   $order          Order.
     * @param  string  $paypalOrderId  PayPal Order id.
     *
     * @return bool
     */
    public static function describes( Order $order, string $paypalOrderId ): bool
    {
        $stored = self::get( $order, 'order_id' );

        return null === $stored || '' === $stored || '' === $paypalOrderId || (string) $stored === $paypalOrderId;
    }

    /**
     * Merges `$values` into `orders.meta.paypal` (recursively, so
     * `[ 'authorization' => [ 'status' => … ] ]` keeps the authorization's
     * other fields) and saves.
     *
     * With `$paypalOrderId`, values recorded for a different PayPal Order
     * (an earlier checkout attempt) are dropped first instead of merged, so
     * one attempt's authorization or capture never mixes with another's.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order          Order.
     * @param  array<string, mixed>  $values         Values to merge.
     * @param  string|null           $paypalOrderId  PayPal Order the values belong to.
     *
     * @return void
     */
    public static function merge( Order $order, array $values, ?string $paypalOrderId = null ): void
    {
        if ( [] === $values ) {
            return;
        }

        if ( ! $order->exists || null === $order->getKey() ) {
            $order->setAttribute( 'meta', self::merged( (array) ( $order->getAttribute( 'meta' ) ?? [] ), $values, $paypalOrderId ) );

            return;
        }

        $meta = DB::transaction( static function () use ( $order, $values, $paypalOrderId ): array {
            $locked = Order::query()->lockForUpdate()->find( $order->getKey() );

            if ( null === $locked ) {
                return self::merged( (array) ( $order->getAttribute( 'meta' ) ?? [] ), $values, $paypalOrderId );
            }

            $meta         = self::merged( (array) ( $locked->getAttribute( 'meta' ) ?? [] ), $values, $paypalOrderId );
            $locked->meta = $meta;
            $locked->save();

            return $meta;
        } );

        // Keep the caller's instance in step without marking it dirty, so a
        // later save of it doesn't write stale meta back.
        $order->setAttribute( 'meta', $meta );
        $order->syncOriginalAttribute( 'meta' );
    }

    /**
     * Moves the order's recorded capture to `$capture`'s status, as PayPal
     * reported it, under the order's row lock.
     *
     * - A different capture than the one the order recorded changes nothing.
     * - The recorded status is open while it is null or `PENDING`; a settled
     *   capture never changes (in particular, never back to `PENDING`).
     * - `PENDING` records the capture id, status, and pending reason, and
     *   returns `'pending'` only when that changed something.
     * - `COMPLETED`, `DECLINED`, `DENIED`, or `FAILED` records the capture id
     *   (when the order had none yet) and the status, and returns
     *   `'completed'` or `'denied'`.
     *
     * @since 1.0.0
     *
     * @param  Order          $order    Order.
     * @param  PayPalCapture  $capture  The capture, fetched from PayPal.
     *
     * @return string|null `'pending'`, `'completed'`, `'denied'`, or null when nothing changed.
     */
    public static function transitionCapture( Order $order, PayPalCapture $capture ): ?string
    {
        $fetched = (string) $capture->status();

        if ( '' === $capture->id() || ( 'PENDING' !== $fetched && ! in_array( $fetched, self::FINAL_CAPTURE_STATES, true ) ) ) {
            return null;
        }

        $decide = static function ( array $meta ) use ( $capture, $fetched ): ?array {
            $paypal   = is_array( $meta[ self::KEY ] ?? null ) ? $meta[ self::KEY ] : [];
            $recorded = isset( $paypal['capture_id'] ) && '' !== (string) $paypal['capture_id'] ? (string) $paypal['capture_id'] : null;
            $status   = isset( $paypal['capture_status'] ) && '' !== (string) $paypal['capture_status'] ? (string) $paypal['capture_status'] : null;

            if ( ( null !== $recorded && $recorded !== $capture->id() ) || ( null !== $status && 'PENDING' !== $status ) ) {
                return null;
            }

            if ( 'PENDING' === $fetched ) {
                $values = [ 'capture_id' => $capture->id(), 'capture_status' => 'PENDING', 'pending_reason' => $capture->pendingReason() ];

                if ( $recorded === $capture->id() && 'PENDING' === $status && ( $paypal['pending_reason'] ?? null ) === $values['pending_reason'] ) {
                    return null;
                }

                $transition = 'pending';
            } else {
                $values     = [ 'capture_id' => $capture->id(), 'capture_status' => $fetched, 'pending_reason' => null ];
                $transition = 'COMPLETED' === $fetched ? 'completed' : 'denied';
            }

            $meta[ self::KEY ] = array_replace( $paypal, $values );

            return [ $meta, $transition ];
        };

        if ( ! $order->exists || null === $order->getKey() ) {
            $result = $decide( (array) ( $order->getAttribute( 'meta' ) ?? [] ) );

            if ( null !== $result ) {
                $order->setAttribute( 'meta', $result[0] );
            }

            return $result[1] ?? null;
        }

        $result = DB::transaction( static function () use ( $order, $decide ): ?array {
            $locked = Order::query()->lockForUpdate()->find( $order->getKey() );
            $result = null === $locked ? null : $decide( (array) ( $locked->getAttribute( 'meta' ) ?? [] ) );

            if ( null === $result ) {
                return null;
            }

            $locked->meta = $result[0];
            $locked->save();

            return $result;
        } );

        if ( null === $result ) {
            return null;
        }

        $order->setAttribute( 'meta', $result[0] );
        $order->syncOriginalAttribute( 'meta' );

        return $result[1];
    }

    /**
     * Records that the order processed webhook event `$eventId`, keeping the
     * latest `$limit` ids in `orders.meta.paypal.webhook_events`. Checked and
     * written under the order's row lock.
     *
     * @since 1.0.0
     *
     * @param  Order   $order    Order.
     * @param  string  $eventId  Event id.
     * @param  int     $limit    How many ids to keep.
     *
     * @return bool False when the order had already processed it.
     */
    public static function rememberEvent( Order $order, string $eventId, int $limit = 50 ): bool
    {
        $remember = static function ( array $meta ) use ( $eventId, $limit ): ?array {
            $paypal = is_array( $meta[ self::KEY ] ?? null ) ? $meta[ self::KEY ] : [];
            $seen   = array_values( array_filter( (array) ( $paypal['webhook_events'] ?? [] ), 'is_string' ) );

            if ( in_array( $eventId, $seen, true ) ) {
                return null;
            }

            $seen[]                   = $eventId;
            $paypal['webhook_events'] = array_slice( $seen, -max( 1, $limit ) );
            $meta[ self::KEY ]        = $paypal;

            return $meta;
        };

        if ( ! $order->exists || null === $order->getKey() ) {
            $meta = $remember( (array) ( $order->getAttribute( 'meta' ) ?? [] ) );

            if ( null !== $meta ) {
                $order->setAttribute( 'meta', $meta );
            }

            return null !== $meta;
        }

        $meta = DB::transaction( static function () use ( $order, $remember ): ?array {
            $locked = Order::query()->lockForUpdate()->find( $order->getKey() );
            $meta   = null === $locked ? null : $remember( (array) ( $locked->getAttribute( 'meta' ) ?? [] ) );

            if ( null === $meta ) {
                return null;
            }

            $locked->meta = $meta;
            $locked->save();

            return $meta;
        } );

        if ( null === $meta ) {
            return false;
        }

        $order->setAttribute( 'meta', $meta );
        $order->syncOriginalAttribute( 'meta' );

        return true;
    }

    /**
     * `$meta` with `$values` merged into its PayPal namespace.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $meta           Order meta.
     * @param  array<string, mixed>  $values         Values to merge.
     * @param  string|null           $paypalOrderId  PayPal Order the values belong to.
     *
     * @return array<string, mixed>
     */
    private static function merged( array $meta, array $values, ?string $paypalOrderId = null ): array
    {
        $current = is_array( $meta[ self::KEY ] ?? null ) ? $meta[ self::KEY ] : [];

        if ( null !== $paypalOrderId && '' !== $paypalOrderId && isset( $current['order_id'] ) && $paypalOrderId !== (string) $current['order_id'] ) {
            $current = [];
        }

        if ( null !== $paypalOrderId && '' !== $paypalOrderId ) {
            $values['order_id'] = $paypalOrderId;
        }

        if ( 'PENDING' === ( $values['capture_status'] ?? null )
            && in_array( $current['capture_status'] ?? null, self::SETTLED_CAPTURE_STATES, true )
            && ( ! isset( $values['capture_id'] ) || (string) $values['capture_id'] === (string) ( $current['capture_id'] ?? '' ) ) ) {
            // A webhook already settled this capture: a pending response
            // read before it doesn't take it back to PENDING.
            unset( $values['capture_status'], $values['pending_reason'] );
        }

        $meta[ self::KEY ] = array_replace_recursive( $current, $values );

        return $meta;
    }
}
