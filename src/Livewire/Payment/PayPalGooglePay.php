<?php

/**
 * The `paypal-google-pay` storefront payment driver.
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
 * Google Pay processed by PayPal, in storefront-livewire's checkout (spec
 * §9.2, §9.6).
 *
 * Google's Pay API is loaded once and the button renders only when
 * `isReadyToPay` passes with PayPal's `Googlepay().config()` payment
 * methods. The environment follows `mode` (`TEST` in the sandbox,
 * `PRODUCTION` live). After `confirmOrder`, a `PAYER_ACTION_REQUIRED`
 * answer runs `initiatePayerAction` (3-D Secure) before
 * {@see PayPalPaymentDriver::confirm()} checks the Order with PayPal.
 *
 * When the device or PayPal says Google Pay can't be used, the driver hides its
 * button, says so politely, and reports it with {@see self::fail()} so the
 * shopper picks another method. The wallet's payment data stays in the
 * wallet's sheet and PayPal's SDK; nothing reaches a Livewire request but
 * the Order id.
 *
 * Registered as `paypal-google-pay` in `PaymentDriverRegistry` only when
 * storefront-livewire is installed.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalGooglePay extends PayPalPaymentDriver
{
    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DRIVER = 'paypal-google-pay';

    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-google-pay';

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        return view( 'ecommerce-paypal::livewire.payment.paypal-wallet', [
            'available' => $this->available(),
            'driver'    => self::DRIVER,
            'wallet'    => __( 'Google Pay' ),
            'paypal'    => $this->scriptSettings( [
                'loadFailed' => __( ':wallet couldn\'t load. Check your connection and reload the page.', [ 'wallet' => __( 'Google Pay' ) ] ),
                'failed'     => __( 'Your :wallet payment couldn\'t be completed. Try again or use another payment method.', [ 'wallet' => __( 'Google Pay' ) ] ),
                'canceled'   => __( 'You closed :wallet before paying. You can try again or choose another payment method.', [ 'wallet' => __( 'Google Pay' ) ] ),
                'ineligible' => __( ':wallet isn\'t available on this device. Choose another payment method.', [ 'wallet' => __( 'Google Pay' ) ] ),
                'confirming' => __( 'Confirming your :wallet payment…', [ 'wallet' => __( 'Google Pay' ) ] ),
            ], [
                'mount' => 'mountGooglePay',
                'label' => $this->totalLabel(),
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
            'mismatch'    => __( 'Your :wallet payment couldn\'t be confirmed. Try again.', [ 'wallet' => __( 'Google Pay' ) ] ),
            'unreachable' => __( 'We couldn\'t check your :wallet payment with PayPal. Try again.', [ 'wallet' => __( 'Google Pay' ) ] ),
            'action'      => __( 'Your bank needs you to finish verifying this payment. Try again.' ),
            'declined'    => __( 'Your :wallet payment wasn\'t approved. Try again or use another payment method.', [ 'wallet' => __( 'Google Pay' ) ] ),
        ];
    }

    /**
     * The name the wallet's sheet shows next to the total: the PayPal brand
     * name, else the app name.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function totalLabel(): string
    {
        $brand = trim( (string) config( 'artisanpack.ecommerce-paypal.brand_name', '' ) );

        return '' !== $brand ? $brand : (string) config( 'app.name', __( 'Total' ) );
    }
}
