<?php

/**
 * Builds PayPal Orders v2 request bodies.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Payloads;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\CartItem;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Support\PayPalLocale;
use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Throwable;

/**
 * Turns an engine cart into a `POST /v2/checkout/orders` body (spec §6.3,
 * §7.1).
 *
 * The engine's totals are the source of truth. Line items and the amount
 * breakdown are converted with {@see PayPalMoney}, and any difference
 * PayPal's decimal rules introduce (HUF and TWD are sent whole) is moved
 * into `discount` / `shipping_discount` or `handling`, so the breakdown
 * always adds up to `amount.value` and `amount.value` always equals the
 * engine total.
 *
 * The body goes through the `ap.ecommerce.paypal.orderPayload` filter
 * `(array $payload, Cart $cart, PayPalGateway $gateway)` before it is
 * returned.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class OrderPayloadBuilder
{
    /**
     * PayPal's length limit for item names and SKUs.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const ITEM_TEXT_LIMIT = 127;

    /**
     * PayPal's length limit for the statement descriptor.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const SOFT_DESCRIPTOR_LIMIT = 22;

    /**
     * The storefront route used as the return URL when the caller passes none.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STOREFRONT_RETURN_ROUTE = 'artisanpack.ecommerce.storefront.checkout.return';

    /**
     * The storefront route used as the cancel URL when the caller passes none.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const STOREFRONT_CANCEL_ROUTE = 'artisanpack.ecommerce.storefront.checkout';

    /**
     * Builds the order body.
     *
     * `$context` keys:
     * - `attempt` (int|string) — makes `invoice_id` unique per checkout
     *   attempt (`cart-{id}-{attempt}`); a random suffix is used without it;
     * - `return_url`, `cancel_url` (string) — where PayPal sends the buyer
     *   back; default to the storefront's checkout return and checkout pages;
     * - `payment_source` (string) — overrides the gateway's source
     *   (`paypal`, `venmo`, `card`, …);
     * - `locale` (string) — overrides the cart's / app's locale.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart being paid for.
     * @param  PayPalGateway         $gateway  Gateway the order is created for.
     * @param  array<string, mixed>  $context  See above.
     *
     * @throws RuntimeException When the cart's totals can't be expressed in PayPal's breakdown.
     *
     * @return array<string, mixed>
     */
    public function build( Cart $cart, PayPalGateway $gateway, array $context = [] ): array
    {
        $currency = new Currency( strtoupper( (string) ( $cart->getAttribute( 'currency' ) ?: $cart->getAttribute( 'total_currency' ) ?: 'USD' ) ) );
        $lines    = $this->lines( $cart, $currency );
        $source   = isset( $context['payment_source'] ) && '' !== $context['payment_source'] ? (string) $context['payment_source'] : $gateway->paymentSource();

        $purchaseUnit = array_filter( [
            'reference_id'    => 'default',
            'custom_id'       => (string) $cart->getKey(),
            'invoice_id'      => $this->invoiceId( $cart, $context ),
            'soft_descriptor' => $this->softDescriptor(),
            'amount'          => $this->amount( $cart, $currency, $lines['item_total'] ),
            'items'           => [] === $lines['items'] ? null : $lines['items'],
            'shipping'        => $lines['requires_shipping'] ? $this->shipping( $cart ) : null,
        ], static fn ( mixed $value ): bool => null !== $value );

        $payload = [
            'intent'         => 'authorize' === strtolower( (string) config( 'artisanpack.ecommerce-paypal.intent', 'capture' ) ) ? 'AUTHORIZE' : 'CAPTURE',
            'purchase_units' => [ $purchaseUnit ],
        ];

        $paymentSource = $this->paymentSource( $cart, $source, $context, $lines['requires_shipping'], isset( $purchaseUnit['shipping'] ) );

        if ( null !== $paymentSource ) {
            $payload['payment_source'] = [ $source => $paymentSource ];
        }

        if ( function_exists( 'applyFilters' ) ) {
            $filtered = applyFilters( 'ap.ecommerce.paypal.orderPayload', $payload, $cart, $gateway );
            $payload  = is_array( $filtered ) ? $filtered : $payload;
        }

        return $payload;
    }

    /**
     * The line items, their total in PayPal units, and whether anything
     * needs shipping.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart      Cart.
     * @param  Currency  $currency  Cart currency.
     *
     * @return array{items: array<int, array<string, mixed>>, item_total: int, requires_shipping: bool}
     */
    protected function lines( Cart $cart, Currency $currency ): array
    {
        $cart->loadMissing( 'items.product', 'items.variant' );

        $items            = [];
        $itemTotal        = 0;
        $requiresShipping = false;

        foreach ( $cart->items as $item ) {
            $quantity = max( 1, (int) $item->quantity );
            $unit     = PayPalMoney::toUnits( new Money( (int) $item->unit_price_amount, $currency ) );
            $physical = $this->isPhysical( $item );

            $requiresShipping = $requiresShipping || $physical;
            $itemTotal += $unit * $quantity;

            $items[] = array_filter( [
                'name'        => $this->limit( $this->itemName( $item ) ),
                'sku'         => $this->itemSku( $item ),
                'quantity'    => (string) $quantity,
                'unit_amount' => PayPalMoney::unitsToPayPal( $unit, $currency ),
                'category'    => $physical ? 'PHYSICAL_GOODS' : 'DIGITAL_GOODS',
            ], static fn ( mixed $value ): bool => null !== $value );
        }

        return [ 'items' => $items, 'item_total' => $itemTotal, 'requires_shipping' => $requiresShipping ];
    }

    /**
     * The purchase unit amount with a breakdown that adds up exactly.
     *
     * Discounts go against the items first and spill over onto shipping
     * (`shipping_discount`). Rounding drift is then reconciled: a shortfall
     * becomes `handling`, an excess a bigger discount.
     *
     * @since 1.0.0
     *
     * @param  Cart      $cart       Cart.
     * @param  Currency  $currency   Cart currency.
     * @param  int       $itemTotal  Item total in PayPal units.
     *
     * @throws RuntimeException When the breakdown can't reach the engine total.
     *
     * @return array<string, mixed>
     */
    protected function amount( Cart $cart, Currency $currency, int $itemTotal ): array
    {
        $units = static fn ( string $attribute ): int => max( 0, PayPalMoney::toUnits( new Money( (int) ( $cart->getAttribute( $attribute ) ?? 0 ), $currency ) ) );

        $total    = $units( 'total_amount' );
        $shipping = $units( 'shipping_amount' );
        $tax      = $this->pricesIncludeTax( $cart ) ? 0 : $units( 'tax_amount' );
        $discount = $units( 'discount_amount' );

        $itemDiscount     = min( $discount, $itemTotal );
        $shippingDiscount = min( $discount - $itemDiscount, $shipping );
        $handling         = 0;

        $drift = $total - ( $itemTotal + $shipping + $tax - $itemDiscount - $shippingDiscount );

        if ( $drift > 0 ) {
            $handling = $drift;
        } elseif ( $drift < 0 ) {
            $excess = -$drift;

            foreach ( [ 'item', 'shipping', 'tax' ] as $bucket ) {
                $room = match ( $bucket ) {
                    'item'     => $itemTotal - $itemDiscount,
                    'shipping' => $shipping - $shippingDiscount,
                    'tax'      => $tax,
                };
                $take = min( $excess, $room );

                match ( $bucket ) {
                    'item'     => $itemDiscount += $take,
                    'shipping' => $shippingDiscount += $take,
                    'tax'      => $tax -= $take,
                };

                $excess -= $take;
            }

            if ( $excess > 0 ) {
                throw new RuntimeException( sprintf( 'Cart %s totals can\'t be expressed as a PayPal amount breakdown.', (string) $cart->getKey() ) );
            }
        }

        $breakdown = [ 'item_total' => PayPalMoney::unitsToPayPal( $itemTotal, $currency ) ];

        foreach ( [ 'shipping' => $shipping, 'handling' => $handling, 'tax_total' => $tax, 'shipping_discount' => $shippingDiscount, 'discount' => $itemDiscount ] as $key => $value ) {
            if ( $value > 0 ) {
                $breakdown[ $key ] = PayPalMoney::unitsToPayPal( $value, $currency );
            }
        }

        return [ ...PayPalMoney::unitsToPayPal( $total, $currency ), 'breakdown' => $breakdown ];
    }

    /**
     * The shipping block: recipient and address from the cart.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<string, mixed>|null Null when the cart has no usable address.
     */
    protected function shipping( Cart $cart ): ?array
    {
        $address = (array) ( $cart->getAttribute( 'shipping_address' ) ?? [] );
        $line1   = trim( (string) ( $address['address1'] ?? '' ) );
        $country = strtoupper( trim( (string) ( $address['country_code'] ?? '' ) ) );

        if ( '' === $line1 || 2 !== strlen( $country ) ) {
            return null;
        }

        $name = trim( trim( (string) ( $address['first_name'] ?? '' ) ) . ' ' . trim( (string) ( $address['last_name'] ?? '' ) ) );

        return array_filter( [
            'type'    => 'SHIPPING',
            'name'    => '' === $name ? null : [ 'full_name' => mb_substr( $name, 0, 300 ) ],
            'address' => array_filter( [
                'address_line_1' => mb_substr( $line1, 0, 300 ),
                'address_line_2' => self::optional( $address, 'address2', 300 ),
                'admin_area_2'   => self::optional( $address, 'city', 120 ),
                'admin_area_1'   => self::optional( $address, 'region_code', 300 ) ?? self::optional( $address, 'region', 300 ),
                'postal_code'    => self::optional( $address, 'postal_code', 60 ),
                'country_code'   => $country,
            ], static fn ( ?string $value ): bool => null !== $value ),
        ], static fn ( mixed $value ): bool => null !== $value );
    }

    /**
     * The `payment_source.{source}` object, or null when the source is set
     * in the browser.
     *
     * PayPal and Venmo get the full experience context (brand, locale, pay
     * now, shipping preference, return and cancel URLs). Cards only take
     * return and cancel URLs (for 3-D Secure redirects). Apple Pay and
     * Google Pay attach their payment source client-side when the shopper
     * confirms (`confirmOrder`), so the order is created without one.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart              Cart.
     * @param  string                $source            Payment source.
     * @param  array<string, mixed>  $context           Build context.
     * @param  bool                  $requiresShipping  Whether anything ships.
     * @param  bool                  $hasAddress        Whether the shipping block was sent.
     *
     * @return array<string, mixed>|null
     */
    protected function paymentSource( Cart $cart, string $source, array $context, bool $requiresShipping, bool $hasAddress ): ?array
    {
        $urls = $this->urls( $context );

        return match ( $source ) {
            'paypal', 'venmo' => [
                'experience_context' => array_filter( [
                    'brand_name'          => $this->brandName(),
                    'locale'              => PayPalLocale::resolve( isset( $context['locale'] ) ? (string) $context['locale'] : ( $cart->getAttribute( 'locale' ) ?: null ) ),
                    'user_action'         => 'PAY_NOW',
                    'shipping_preference' => match ( true ) {
                        ! $requiresShipping => 'NO_SHIPPING',
                        $hasAddress         => 'SET_PROVIDED_ADDRESS',
                        default             => 'GET_FROM_FILE',
                    },
                    ...$urls,
                ], static fn ( ?string $value ): bool => null !== $value && '' !== $value ),
            ],
            'card'  => [] === $urls ? null : [ 'experience_context' => $urls ],
            default => null,
        };
    }

    /**
     * The return and cancel URLs.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Build context.
     *
     * @return array<string, string>
     */
    protected function urls( array $context ): array
    {
        $return = isset( $context['return_url'] ) && '' !== $context['return_url'] ? (string) $context['return_url'] : self::routeUrl( self::STOREFRONT_RETURN_ROUTE );
        $cancel = isset( $context['cancel_url'] ) && '' !== $context['cancel_url'] ? (string) $context['cancel_url'] : ( self::routeUrl( self::STOREFRONT_CANCEL_ROUTE ) ?? $return );

        return array_filter( [ 'return_url' => $return, 'cancel_url' => $cancel ], static fn ( ?string $value ): bool => null !== $value );
    }

    /**
     * `cart-{id}-{attempt}`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  Build context.
     *
     * @return string
     */
    protected function invoiceId( Cart $cart, array $context ): string
    {
        $attempt = isset( $context['attempt'] ) && '' !== (string) $context['attempt']
            ? (string) $context['attempt']
            : Str::lower( Str::random( 10 ) );

        return mb_substr( 'cart-' . $cart->getKey() . '-' . $attempt, 0, self::ITEM_TEXT_LIMIT );
    }

    /**
     * The brand shown on PayPal's pages: the configured brand name, else the
     * store name, else the app name.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected function brandName(): ?string
    {
        foreach ( [ 'artisanpack.ecommerce-paypal.brand_name', 'artisanpack.ecommerce.notifications.store_name', 'app.name' ] as $key ) {
            $value = trim( (string) config( $key, '' ) );

            if ( '' !== $value ) {
                return mb_substr( $value, 0, self::ITEM_TEXT_LIMIT );
            }
        }

        return null;
    }

    /**
     * The configured statement descriptor, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected function softDescriptor(): ?string
    {
        $value = trim( (string) config( 'artisanpack.ecommerce-paypal.soft_descriptor', '' ) );

        return '' === $value ? null : mb_substr( $value, 0, self::SOFT_DESCRIPTOR_LIMIT );
    }

    /**
     * Whether the cart's prices already include tax.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return bool
     */
    protected function pricesIncludeTax( Cart $cart ): bool
    {
        $meta = (array) ( $cart->getAttribute( 'meta' ) ?? [] );

        return (bool) ( $meta['tax']['prices_include_tax'] ?? config( 'artisanpack.ecommerce.tax.prices_include_tax', false ) );
    }

    /**
     * Whether a line ships, matching the engine's own rule: its product's
     * type needs fulfillment.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Line.
     *
     * @return bool
     */
    protected function isPhysical( CartItem $item ): bool
    {
        $product = $item->product;

        if ( null === $product || $product->typeIsMissing() ) {
            return false;
        }

        try {
            return $product->productType()->requiresFulfillment();
        } catch ( Throwable ) {
            return false;
        }
    }

    /**
     * The line's display name.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Line.
     *
     * @return string
     */
    protected function itemName( CartItem $item ): string
    {
        $name = trim( (string) ( $item->product?->name ?? '' ) );

        return '' === $name ? __( 'Item' ) : $name;
    }

    /**
     * The line's SKU (the variant's, else the product's), or null.
     *
     * @since 1.0.0
     *
     * @param  CartItem  $item  Line.
     *
     * @return string|null
     */
    protected function itemSku( CartItem $item ): ?string
    {
        $sku = trim( (string) ( $item->variant?->sku ?? $item->product?->sku ?? '' ) );

        return '' === $sku ? null : $this->limit( $sku );
    }

    /**
     * Truncates to PayPal's item text limit.
     *
     * @since 1.0.0
     *
     * @param  string  $value  Text.
     *
     * @return string
     */
    protected function limit( string $value ): string
    {
        return mb_substr( $value, 0, self::ITEM_TEXT_LIMIT );
    }

    /**
     * A named route's URL, or null when it isn't registered.
     *
     * @since 1.0.0
     *
     * @param  string  $name  Route name.
     *
     * @return string|null
     */
    private static function routeUrl( string $name ): ?string
    {
        return Route::has( $name ) ? route( $name ) : null;
    }

    /**
     * A trimmed, truncated address field, or null when empty.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $address  Address.
     * @param  string                $key      Field.
     * @param  int                   $limit    Length limit.
     *
     * @return string|null
     */
    private static function optional( array $address, string $key, int $limit ): ?string
    {
        $value = trim( (string) ( $address[ $key ] ?? '' ) );

        return '' === $value ? null : mb_substr( $value, 0, $limit );
    }
}
