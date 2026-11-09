<?php

/**
 * The `paypal-card-fields` storefront payment driver.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Livewire\Payment;

use Illuminate\Contracts\View\View;

/**
 * PayPal's advanced card fields in storefront-livewire's checkout (spec
 * §9.2, §9.4).
 *
 * The number, expiry, security code, and name fields are PayPal-hosted
 * iframes mounted by `paypal-checkout.js` inside `wire:ignore`, so card data
 * never enters this component, its properties, or a Livewire request (the
 * store stays SAQ-A). Each field has a visible label and an inline error,
 * announced as the shopper leaves it. "Pay now" submits the fields, which
 * runs 3-D Secure (`methods.card.three_d_secure`) in PayPal's modal, and is
 * disabled while that runs. {@see PayPalPaymentDriver::confirm()} then
 * refuses a liability shift of `NO` / `UNKNOWN` when
 * `require_liability_shift` is on, so the order is never placed or
 * captured. A signed-in shopper can tick "Save this card" when vaulting is
 * on (spec §10.2).
 *
 * Registered as `paypal-card-fields` in `PaymentDriverRegistry` only when
 * storefront-livewire is installed.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalCardFields extends PayPalPaymentDriver
{
    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DRIVER = 'paypal-card-fields';

    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-card-fields';

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        return view( 'ecommerce-paypal::livewire.payment.paypal-card-fields', [
            'available' => $this->available(),
            'canSave'   => $this->vaultAvailable(),
            'fields'    => [
                'name'   => __( 'Name on card' ),
                'number' => __( 'Card number' ),
                'expiry' => __( 'Expiration date' ),
                'cvv'    => __( 'Security code' ),
            ],
            'paypal'    => $this->scriptSettings( [
                'loadFailed' => __( 'The card form couldn\'t load. Check your connection and reload the page.' ),
                'failed'     => __( 'Your card payment couldn\'t be completed. Check your details or use another payment method.' ),
                'invalid'    => __( 'Check the highlighted card details.' ),
                'ineligible' => __( 'Card payments aren\'t available for this order. Choose another payment method.' ),
                'processing' => __( 'Processing your card. Your bank may ask you to verify it.' ),
                'fields'     => [
                    'name'   => __( 'Enter the name on the card.' ),
                    'number' => __( 'Enter a valid card number.' ),
                    'expiry' => __( 'Enter a valid expiration date.' ),
                    'cvv'    => __( 'Enter a valid security code.' ),
                ],
            ] ),
        ] );
    }

    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function driverKey(): string
    {
        return self::DRIVER;
    }

    /**
     * The confirmation failure messages.
     *
     * @since 1.0.0
     *
     * @return array{mismatch: string, unreachable: string, action: string, declined: string}
     */
    protected function confirmMessages(): array
    {
        return [
            'mismatch'    => __( 'Your card payment couldn\'t be confirmed. Try again.' ),
            'unreachable' => __( 'We couldn\'t check your card payment with PayPal. Try again.' ),
            'action'      => __( 'Your bank needs you to finish verifying this card. Try again.' ),
            'declined'    => __( 'Your card wasn\'t approved. Try again or use another payment method.' ),
        ];
    }
}
