<?php

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use Illuminate\Auth\GenericUser;

/**
 * Signed-in shoppers for the vault tests.
 *
 * The engine finds a user's customer by `user_id`, so a `GenericUser` is
 * enough; no users table is needed.
 *
 * @since 1.0.0
 */
final class Shoppers
{
    /**
     * Signs in a user and returns their engine customer.
     *
     * @since 1.0.0
     *
     * @param  int  $userId  User id.
     *
     * @return Customer
     */
    public static function signIn( int $userId = 7 ): Customer
    {
        $customer = Customer::factory()->create( [ 'user_id' => $userId ] );

        test()->actingAs( new GenericUser( [ 'id' => $userId ] ) );

        return $customer;
    }

    /**
     * A pending checkout cart (see {@see PayPalWebhooks::pendingCart()})
     * owned by `$customer`.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer    Customer.
     * @param  string    $gatewayKey  Gateway key.
     *
     * @return Cart
     */
    public static function cart( Customer $customer, string $gatewayKey = 'paypal' ): Cart
    {
        $cart = PayPalWebhooks::pendingCart( $gatewayKey );

        $cart->forceFill( [ 'customer_id' => $customer->getKey() ] )->save();

        return $cart->refresh();
    }
}
