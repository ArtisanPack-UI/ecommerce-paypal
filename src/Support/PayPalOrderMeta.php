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
use Illuminate\Support\Facades\DB;

/**
 * The `orders.meta.paypal` namespace (spec §3): the PayPal Order, capture,
 * and authorization ids, payer details, and the authorization lifecycle.
 *
 * Writes merge into the stored row under a row lock, so they never clobber
 * meta the engine wrote in between (the finalize claim, the capture
 * reference). An order that isn't saved yet is updated in memory only.
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
     * Forgets that the order processed webhook event `$eventId`, so it is
     * processed again on retry.
     *
     * @since 1.0.0
     *
     * @param  Order   $order    Order.
     * @param  string  $eventId  Event id.
     *
     * @return void
     */
    public static function forgetEvent( Order $order, string $eventId ): void
    {
        $forget = static function ( array $meta ) use ( $eventId ): array {
            $paypal                   = is_array( $meta[ self::KEY ] ?? null ) ? $meta[ self::KEY ] : [];
            $paypal['webhook_events'] = array_values( array_filter(
                (array) ( $paypal['webhook_events'] ?? [] ),
                static fn ( mixed $seen ): bool => $seen !== $eventId,
            ) );
            $meta[ self::KEY ] = $paypal;

            return $meta;
        };

        if ( ! $order->exists || null === $order->getKey() ) {
            $order->setAttribute( 'meta', $forget( (array) ( $order->getAttribute( 'meta' ) ?? [] ) ) );

            return;
        }

        $meta = DB::transaction( static function () use ( $order, $forget ): ?array {
            $locked = Order::query()->lockForUpdate()->find( $order->getKey() );

            if ( null === $locked ) {
                return null;
            }

            $locked->meta = $forget( (array) ( $locked->getAttribute( 'meta' ) ?? [] ) );
            $locked->save();

            return $locked->meta;
        } );

        if ( null !== $meta ) {
            $order->setAttribute( 'meta', $meta );
            $order->syncOriginalAttribute( 'meta' );
        }
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

        $meta[ self::KEY ] = array_replace_recursive( $current, $values );

        return $meta;
    }
}
