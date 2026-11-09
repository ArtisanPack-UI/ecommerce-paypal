<?php

/**
 * Ecommerce PayPal gateway configuration.
 *
 * Read under the `artisanpack.ecommerce-paypal` key (spec §5). Most keys are
 * also editable from the engine's settings screen (the `paypal` group); a
 * value saved there overrides the one below. The client secret and webhook
 * ids are credentials: they are only ever read from here (and the env), and
 * the settings screen shows them as configured or not.
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
    | The PayPal gateways are only registered when this is true and both the
    | client id and the client secret are set.
    |
    */

    'enabled' => env( 'PAYPAL_ENABLED', false ),

    /*
    |--------------------------------------------------------------------------
    | Mode
    |--------------------------------------------------------------------------
    |
    | `sandbox` or `live`. Selects the PayPal API host (api-m.paypal.com or
    | api-m.sandbox.paypal.com), the web host, and the dashboard links.
    | The settings screen refuses to save PayPal enabled in `live` mode when
    | PayPal rejects the credentials. It can't stop a live switch when
    | PayPal is unreachable or the credentials are incomplete, so run
    | `php artisan ecommerce-paypal:verify-credentials` after going live.
    |
    */

    'mode' => env( 'PAYPAL_MODE', 'sandbox' ),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | The REST app's client id and secret, from the PayPal developer
    | dashboard. The client id is public (the JS SDK loads with it); the
    | secret never leaves the server.
    |
    */

    'client_id' => env( 'PAYPAL_CLIENT_ID' ),

    'client_secret' => env( 'PAYPAL_CLIENT_SECRET' ),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | `webhook_id` is the id `ecommerce-paypal:webhooks:register` prints.
    | During a rotation, list both ids separated by commas: deliveries signed
    | for either one are accepted.
    |
    | `webhook_verification` is `local` (check the signature against PayPal's
    | certificate, no API call) or `postback` (ask PayPal's
    | verify-webhook-signature endpoint).
    |
    */

    'webhook_id' => env( 'PAYPAL_WEBHOOK_ID' ),

    'webhook_verification' => env( 'PAYPAL_WEBHOOK_VERIFICATION', 'local' ),

    /*
    |--------------------------------------------------------------------------
    | Intent
    |--------------------------------------------------------------------------
    |
    | `capture` takes the money when the order is placed. `authorize` only
    | holds it, and the capture happens later (on shipment, or after a fraud
    | review).
    |
    */

    'intent' => env( 'PAYPAL_INTENT', 'capture' ),

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | `brand_name` is shown on PayPal's pages; when empty, the store name is
    | used. `soft_descriptor` is the text on the buyer's card statement
    | (22 characters at most).
    |
    */

    'brand_name' => env( 'PAYPAL_BRAND_NAME' ),

    'soft_descriptor' => env( 'PAYPAL_SOFT_DESCRIPTOR' ),

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    |
    | Each method is its own checkout choice (spec §6.1):
    |
    | - `paypal`     — PayPal buttons, plus Pay Later and Venmo where the
    |                  buyer is eligible. `button` styles the buttons.
    | - `card`       — Advanced card fields. `three_d_secure` is
    |                  `SCA_WHEN_REQUIRED` or `SCA_ALWAYS`; with
    |                  `require_liability_shift`, a card whose 3-D Secure
    |                  result doesn't shift liability is refused.
    | - `apple_pay`  — Needs the domain registered in the PayPal dashboard.
    |                  `domain_association` is Apple's domain association
    |                  file: its contents, an absolute path to it (named
    |                  `apple-developer-merchantid-domain-association`), or
    |                  an HTTPS URL it can be fetched from. When empty, the
    |                  file in storage/app/ecommerce-paypal/ is used. The
    |                  package serves it at /.well-known/…; switch
    |                  `serve_domain_association` off when the web server
    |                  serves the file itself.
    | - `google_pay` — Google Pay.
    |
    */

    'methods' => [
        'paypal' => [
            'enabled'   => true,
            'pay_later' => true,
            'venmo'     => true,
            'button'    => [
                'layout' => 'vertical',
                'color'  => 'gold',
                'shape'  => 'rect',
                'label'  => 'paypal',
            ],
        ],
        'card' => [
            'enabled'                 => false,
            'three_d_secure'          => 'SCA_WHEN_REQUIRED',
            'require_liability_shift' => true,
        ],
        'apple_pay' => [
            'enabled'                  => false,
            'domain_association'       => env( 'PAYPAL_APPLE_PAY_DOMAIN_ASSOCIATION' ),
            'serve_domain_association' => (bool) env( 'PAYPAL_APPLE_PAY_SERVE_DOMAIN_ASSOCIATION', true ),
        ],
        'google_pay' => [
            'enabled' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pay Later messaging
    |--------------------------------------------------------------------------
    |
    | Where PayPal's Pay Later messages are shown.
    |
    */

    'pay_later_messaging' => [
        'checkout' => true,
        'cart'     => false,
        'product'  => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vault
    |--------------------------------------------------------------------------
    |
    | Lets signed-in shoppers save PayPal and cards for later. Needs the
    | `paypal` or `card` method enabled.
    |
    */

    'vault' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeouts in seconds, and how many times a request that timed out or got
    | a 429 / 5xx is retried (with the same PayPal-Request-Id, so a retry
    | never moves money twice). TLS verification is always on.
    |
    */

    'http' => [
        'timeout'         => 15,
        'connect_timeout' => 5,
        'retries'         => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | `channel` is the log channel (null uses the default). PayPal debug ids
    | are always logged on errors; request and response bodies are logged
    | only with `debug`, with credentials, tokens, card details, and payer
    | personal data redacted.
    |
    */

    'logging' => [
        'channel' => env( 'PAYPAL_LOG_CHANNEL' ),
        'debug'   => (bool) env( 'PAYPAL_LOG_DEBUG', false ),
    ],

];
