<?php

/**
 * The `paypal-buttons` storefront payment driver.
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
 * PayPal's Smart Buttons (PayPal, Pay Later, Venmo) in storefront-livewire's
 * checkout (spec §9.2, §9.3).
 *
 * The buttons are mounted by `paypal-checkout.js` inside `wire:ignore`.
 * The PayPal Order was created server-side, so the SDK's `createOrder`
 * just returns the session reference. When the buyer approves,
 * {@see PayPalPaymentDriver::confirm()} checks the Order with PayPal before
 * telling the checkout it is confirmed. A signed-in shopper can tick "Save
 * PayPal for faster checkout" when vaulting is on (spec §10.2). A canceled
 * popup keeps the shopper on the payment step with a polite message and
 * focus on the step heading; an SDK error is reported with
 * {@see self::fail()}.
 *
 * When the capture at finalize answered `instrument_declined`, the gateway's
 * client config sets `options.restart`: the re-mounted buttons say so and
 * reopen PayPal on the same Order for the buyer to pick another funding
 * source.
 *
 * Registered as `paypal-buttons` in `PaymentDriverRegistry` only when
 * storefront-livewire is installed; a host's `payments.drivers` config can
 * replace it.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalButtons extends PayPalPaymentDriver
{
    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DRIVER = 'paypal-buttons';

    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-buttons';

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        $options = $this->options();

        return view( 'ecommerce-paypal::livewire.payment.paypal-buttons', [
            'available' => $this->available(),
            'restart'   => true === ( $options['restart'] ?? false ),
            'canSave'   => $this->vaultAvailable(),
            'paypal'    => $this->scriptSettings( [
                'loadFailed' => __( 'PayPal couldn\'t load. Check your connection and reload the page.' ),
                'failed'     => __( 'Your PayPal payment couldn\'t be completed. Try again or use another payment method.' ),
                'canceled'   => __( 'You closed PayPal before paying. You can try again or choose another payment method.' ),
                'ineligible' => __( 'PayPal isn\'t available for this order. Choose another payment method.' ),
                'confirming' => __( 'Confirming your PayPal payment…' ),
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
            'mismatch'    => __( 'Your PayPal payment couldn\'t be confirmed. Try again.' ),
            'unreachable' => __( 'We couldn\'t check your payment with PayPal. Try again.' ),
            'action'      => __( 'PayPal needs you to finish another step. Try again.' ),
            'declined'    => __( 'Your PayPal payment wasn\'t approved. Try again or use another payment method.' ),
        ];
    }
}
