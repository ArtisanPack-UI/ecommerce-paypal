<?php

/**
 * The `paypal-card` gateway.
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
 * Advanced card fields hosted by PayPal, with 3-D Secure.
 *
 * PayPal posts this gateway's webhooks to the `paypal` endpoint, which
 * reconciles its sessions ({@see PayPalWalletGateway::webhookGatewayKeys()}).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalCardGateway extends PayPalGateway
{
    /**
     * The gateway key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'paypal-card';

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
        return __( 'Credit or debit card' );
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
        return 'card';
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
        return 'card';
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
        return 'paypal-card-fields';
    }

    /**
     * Cards can be saved when vaulting is on.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsSavedInstruments(): bool
    {
        return (bool) config( 'artisanpack.ecommerce-paypal.vault.enabled', false );
    }

    /**
     * The 3-D Secure setting and liability-shift policy.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function driverOptions(): array
    {
        return [
            'three_d_secure'          => (string) config( 'artisanpack.ecommerce-paypal.methods.card.three_d_secure', 'SCA_WHEN_REQUIRED' ),
            'require_liability_shift' => (bool) config( 'artisanpack.ecommerce-paypal.methods.card.require_liability_shift', true ),
        ];
    }
}
