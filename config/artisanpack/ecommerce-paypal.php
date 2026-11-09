<?php

/**
 * Ecommerce PayPal gateway configuration.
 *
 * Read under the `artisanpack.ecommerce-paypal` key. The full set of keys
 * (credentials, intent, methods, Pay Later, vault, HTTP, logging) lands with
 * the settings group in spec §5.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The PayPal gateways are only registered when this is true and the
    | credentials are present.
    |
    */

    'enabled' => env( 'PAYPAL_ENABLED', false ),

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | `sandbox` or `live`. Selects the PayPal API and web hosts.
    |
    */

    'mode' => env( 'PAYPAL_MODE', 'sandbox' ),

];
