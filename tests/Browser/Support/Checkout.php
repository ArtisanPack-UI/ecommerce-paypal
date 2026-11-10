<?php

declare( strict_types=1 );

namespace Tests\Browser\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Product;
use ArtisanPackUI\Ecommerce\Models\ProductPrice;
use ArtisanPackUI\Ecommerce\Models\ShippingMethod;
use ArtisanPackUI\Ecommerce\Models\ShippingZone;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Services\StorefrontCartService;
use ArtisanPackUI\Ecommerce\ValueObjects\Address;

/**
 * Checkout set-up for the browser suite: a signed-in shopper's cart with
 * contact, address, and shipping already chosen, so a test opens the
 * storefront checkout at its payment step.
 *
 * @since 1.0.0
 */
final class Checkout
{
    /**
     * The storefront checkout path.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PATH = '/shop/checkout';

    /**
     * A cart for `$customer` holding one $20.00 mug with $5.00 shipping
     * to Chicago, ready for its payment.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  The signed-in shopper.
     *
     * @return Cart
     */
    public static function readyCart( Customer $customer ): Cart
    {
        $product = Product::factory()->create( [ 'name' => 'Enamel mug' ] );
        ProductPrice::factory()->forPriceable( $product )->create( [ 'currency' => 'USD', 'price_amount' => 2_000 ] );

        $zone = ShippingZone::factory()->create( [ 'name' => 'US', 'country_codes' => [ 'US' ] ] );
        ShippingMethod::factory()->create( [ 'zone_id' => $zone->id, 'key' => 'flat-rate', 'label' => 'Standard', 'config' => [ 'amount' => 500 ], 'position' => 0 ] );

        $carts    = app( StorefrontCartService::class );
        $checkout = app( CheckoutService::class );
        $cart     = $carts->create( 'USD' );

        $cart->forceFill( [ 'customer_id' => $customer->getKey() ] )->save();

        $carts->addItem( $cart, $product->id, null, 1 );
        $checkout->setEmail( $cart, (string) ( $customer->email ?: 'ada@example.test' ) );
        $checkout->setAddress( $cart, new Address( address1: '1 Main St', city: 'Chicago', countryCode: 'US', firstName: 'Ada', lastName: 'Lovelace', regionCode: 'IL', postalCode: '60601' ) );
        $checkout->setShippingMethod( $cart, $checkout->shippingRates( $cart )->first()->id() );

        return $cart->refresh();
    }
}
