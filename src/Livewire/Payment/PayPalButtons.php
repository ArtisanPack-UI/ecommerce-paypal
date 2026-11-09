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

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommerceStorefrontLivewire\Livewire\Payment\PaymentDriver;
use Illuminate\Contracts\View\View;

/**
 * PayPal's Smart Buttons (PayPal, Pay Later, Venmo) in storefront-livewire's
 * checkout (spec §9.2, §9.3).
 *
 * The buttons are mounted by `paypal-checkout.js` inside `wire:ignore`.
 * The PayPal Order was created server-side, so the SDK's `createOrder`
 * just returns the session reference. When the buyer approves,
 * {@see self::confirm()} checks the Order with PayPal before telling the
 * checkout it is confirmed. A canceled popup keeps the shopper on the
 * payment step with a polite message and focus on the step heading; an SDK
 * error is reported with {@see self::fail()}.
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
class PayPalButtons extends PaymentDriver
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
     * Checks the Order the buyer approved and reports it to the checkout.
     * Called by the view's script from the SDK's `onApprove`.
     *
     * @since 1.0.0
     *
     * @param  string|null  $orderId  The PayPal Order the SDK approved.
     *
     * @return void
     */
    public function confirm( ?string $orderId = null ): void
    {
        if ( null === $orderId || '' === $this->reference || ! hash_equals( $this->reference, $orderId ) ) {
            $this->failed( __( 'Your PayPal payment couldn\'t be confirmed. Try again.' ) );

            return;
        }

        $session = $this->session();

        if ( null === $session ) {
            $this->failed( __( 'We couldn\'t check your payment with PayPal. Try again.' ) );

            return;
        }

        if ( $session->isConfirmed() ) {
            $this->confirmed();

            return;
        }

        $this->failed( $session->requiresAction()
            ? __( 'PayPal needs you to finish another step. Try again.' )
            : __( 'Your PayPal payment wasn\'t approved. Try again or use another payment method.' ) );
    }

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        $clientId = is_string( $this->config['publishable_key'] ?? null ) ? trim( $this->config['publishable_key'] ) : '';
        $orderId  = is_string( $this->config['client_secret'] ?? null ) ? trim( $this->config['client_secret'] ) : '';
        $options  = is_array( $this->config['options'] ?? null ) ? $this->config['options'] : [];

        return view( 'ecommerce-paypal::livewire.payment.paypal-buttons', [
            'available' => '' !== $clientId && '' !== $orderId,
            'restart'   => true === ( $options['restart'] ?? false ),
            'paypal'    => [
                'module'  => self::moduleUrl(),
                'config'  => [
                    'driver'          => self::DRIVER,
                    'publishable_key' => $clientId,
                    'client_secret'   => $orderId,
                    'options'         => $options,
                ],
                'heading'  => 'ec-checkout-step-payment',
                'messages' => [
                    'loadFailed' => __( 'PayPal couldn\'t load. Check your connection and reload the page.' ),
                    'failed'     => __( 'Your PayPal payment couldn\'t be completed. Try again or use another payment method.' ),
                    'canceled'   => __( 'You closed PayPal before paying. You can try again or choose another payment method.' ),
                    'ineligible' => __( 'PayPal isn\'t available for this order. Choose another payment method.' ),
                    'confirming' => __( 'Confirming your PayPal payment…' ),
                ],
            ],
        ] );
    }

    /**
     * Where the published `paypal-checkout.js` is served from, with the
     * package version as a cache buster.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function moduleUrl(): string
    {
        return asset( 'vendor/' . EcommercePayPalServiceProvider::VIEW_NAMESPACE . '/paypal-checkout.js' ) . '?v=' . rawurlencode( EcommercePayPalServiceProvider::VERSION );
    }
}
