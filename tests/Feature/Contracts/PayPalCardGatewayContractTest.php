<?php

/**
 * PayPalCardGateway contract test.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;

/**
 * The engine's `PaymentGatewayContractTest` for the card fields gateway (`paypal-card`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalCardGatewayContractTest extends PayPalSiblingGatewayContractTestCase
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
        return PayPalCardGateway::KEY;
    }
}
