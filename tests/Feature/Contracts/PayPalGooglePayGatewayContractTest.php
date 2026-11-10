<?php

/**
 * PayPalGooglePayGateway contract test.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;

/**
 * The engine's `PaymentGatewayContractTest` for the Google Pay gateway (`paypal-google-pay`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalGooglePayGatewayContractTest extends PayPalSiblingGatewayContractTestCase
{
    /**
     * The gateway under test.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function gatewayKey(): string
    {
        return PayPalGooglePayGateway::KEY;
    }
}
