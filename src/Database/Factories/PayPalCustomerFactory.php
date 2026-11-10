<?php

/**
 * PayPalCustomer factory.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Database\Factories;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPalCustomer>
 *
 * @since 1.0.0
 */
class PayPalCustomerFactory extends Factory
{
    /**
     * @var class-string<PayPalCustomer>
     */
    protected $model = PayPalCustomer::class;

    /**
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id'        => Customer::factory(),
            'paypal_customer_id' => strtoupper( $this->faker->unique()->bothify( '??##??##??' ) ),
            'mode'               => 'sandbox',
        ];
    }

    /**
     * State: a live-mode customer.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function live(): static
    {
        return $this->state( [ 'mode' => 'live' ] );
    }
}
