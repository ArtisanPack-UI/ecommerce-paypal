<?php

/**
 * The `paypal-google-pay` gateway.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Gateways;

/**
 * Google Pay processed by PayPal.
 *
 * PayPal posts this gateway's webhooks to the `paypal` endpoint, which
 * reconciles its sessions ({@see PayPalWalletGateway::webhookGatewayKeys()}).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalGooglePayGateway extends PayPalGateway
{
    /**
     * The gateway key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'paypal-google-pay';

    /**
     * The gateway key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function key(): string
    {
        return self::KEY;
    }

    /**
     * The checkout label.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        return __( 'Google Pay' );
    }

    /**
     * The PayPal payment source.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function paymentSource(): string
    {
        return 'google_pay';
    }

    /**
     * The config method.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function method(): string
    {
        return 'google_pay';
    }

    /**
     * The storefront driver.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function driver(): string
    {
        return 'paypal-google-pay';
    }
}
