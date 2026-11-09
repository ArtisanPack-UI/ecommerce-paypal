<?php

/**
 * PayPalPaymentToken factory.
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
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPalPaymentToken>
 *
 * @since 1.0.0
 */
class PayPalPaymentTokenFactory extends Factory
{
    /**
     * @var class-string<PayPalPaymentToken>
     */
    protected $model = PayPalPaymentToken::class;

    /**
     * A saved card by default.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id'     => Customer::factory(),
            'paypal_token_id' => strtolower( $this->faker->unique()->bothify( '#??##??#' ) ),
            'gateway_key'     => PayPalCardGateway::KEY,
            'type'            => 'card',
            'display'         => [ 'brand' => 'VISA', 'last_digits' => (string) $this->faker->numberBetween( 1000, 9999 ), 'expiry' => '2030-12' ],
            'is_default'      => false,
            'mode'            => 'sandbox',
        ];
    }

    /**
     * State: a saved PayPal wallet.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function paypal(): static
    {
        return $this->state( fn (): array => [
            'gateway_key' => PayPalWalletGateway::KEY,
            'type'        => 'paypal',
            'display'     => [ 'email' => $this->faker->safeEmail() ],
        ] );
    }

    /**
     * State: the customer's default.
     *
     * @since 1.0.0
     *
     * @return static
     */
    public function default(): static
    {
        return $this->state( [ 'is_default' => true ] );
    }

    /**
     * State: a live-mode token.
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
