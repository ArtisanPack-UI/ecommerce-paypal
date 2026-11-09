<?php

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds unsaved carts with their lines and products already loaded, so
 * gateway and payload tests don't need the database.
 *
 * @since 1.0.0
 */
final class CartBuilder
{
    /**
     * Builds a cart.
     *
     * Each line is `[ name, unit minor amount, quantity, type, sku, variant sku ]`
     * (type defaults to `simple`).
     *
     * @since 1.0.0
     *
     * @param  array<int, array<int, mixed>>  $lines       Lines.
     * @param  array<string, mixed>           $attributes  Cart attributes (totals, currency, address, meta).
     *
     * @return Cart
     */
    public static function make( array $lines, array $attributes = [] ): Cart
    {
        $currency = (string) ( $attributes['currency'] ?? 'USD' );
        $items    = [];
        $subtotal = 0;

        foreach ( $lines as $index => $line ) {
            [ $name, $unit, $quantity ] = $line;

            $product = new Product();
            $product->forceFill( [ 'id' => $index + 1, 'name' => $name, 'type' => $line[3] ?? 'simple', 'sku' => $line[4] ?? null ] );

            $item = new CartItem();
            $item->forceFill( [
                'id'                  => $index + 1,
                'product_id'          => $index + 1,
                'quantity'            => $quantity,
                'unit_price_amount'   => $unit,
                'unit_price_currency' => $currency,
            ] );
            $item->setRelation( 'product', $product );

            $variant = null;

            if ( isset( $line[5] ) ) {
                $variant = new ProductVariant();
                $variant->forceFill( [ 'sku' => $line[5] ] );
            }

            $item->setRelation( 'variant', $variant );

            $items[]   = $item;
            $subtotal += $unit * $quantity;
        }

        $cart = new Cart();
        $cart->forceFill( array_merge( [
            'id'              => 42,
            'currency'        => $currency,
            'subtotal_amount' => $subtotal,
            'discount_amount' => 0,
            'tax_amount'      => 0,
            'shipping_amount' => 0,
            'meta'            => [],
        ], $attributes ) );

        if ( ! array_key_exists( 'total_amount', $attributes ) ) {
            $cart->total_amount = $cart->subtotal_amount - $cart->discount_amount + $cart->shipping_amount + $cart->tax_amount;
        }

        $cart->setRelation( 'items', new Collection( $items ) );

        return $cart;
    }
}
