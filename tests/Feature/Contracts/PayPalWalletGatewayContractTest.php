<?php

/**
 * PayPalWalletGateway contract test.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace Tests\Feature\Contracts;

use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;

/**
 * The engine's `PaymentGatewayContractTest` for the PayPal buttons gateway (`paypal`), which also receives the family's webhooks.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWalletGatewayContractTest extends PayPalGatewayContractTestCase
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
        return PayPalWalletGateway::KEY;
    }
}
