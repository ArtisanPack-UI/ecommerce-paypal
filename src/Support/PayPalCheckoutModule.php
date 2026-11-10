<?php

/**
 * The published PayPal checkout module.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Support;

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;

/**
 * Where `paypal-checkout.js` (spec §9.1) is served from once published.
 *
 * Kept free of Livewire and storefront classes, so the Pay Later
 * components load without storefront-livewire installed.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalCheckoutModule
{
    /**
     * The module's URL, with the package version as a cache buster.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function url(): string
    {
        return asset( 'vendor/' . EcommercePayPalServiceProvider::VIEW_NAMESPACE . '/paypal-checkout.js' ) . '?v=' . rawurlencode( EcommercePayPalServiceProvider::VERSION );
    }
}
