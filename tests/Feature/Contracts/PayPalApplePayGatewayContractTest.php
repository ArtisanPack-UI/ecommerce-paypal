<?php

/**
 * PayPalApplePayGateway contract test.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;

/**
 * The engine's `PaymentGatewayContractTest` for the Apple Pay gateway (`paypal-apple-pay`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalApplePayGatewayContractTest extends PayPalSiblingGatewayContractTestCase
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
        return PayPalApplePayGateway::KEY;
    }
}
