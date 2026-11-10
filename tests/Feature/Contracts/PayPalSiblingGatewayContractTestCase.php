<?php

/**
 * Contract test base for the gateways that share PayPal's webhook endpoint.
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
 * The card, Apple Pay, and Google Pay gateways (spec D4).
 *
 * PayPal posts every event for the account to one URL,
 * `ecommerce/webhooks/paypal`, and `paypal` verifies and reconciles it for
 * the whole family ({@see PayPalWalletGateway::webhookGatewayKeys()}). A
 * delivery to a sibling's own `{provider}` URL is never PayPal's, so the
 * sibling refuses it unverified (`use_paypal_endpoint`) rather than
 * verifying a second copy.
 *
 * The engine's webhook case therefore checks the family's verification
 * where it happens: a signed delivery verifies through the shared `paypal`
 * gateway and an unsigned one doesn't, and the sibling itself verifies
 * neither. Every other case of the suite runs unchanged.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalSiblingGatewayContractTestCase extends PayPalGatewayContractTestCase
{
    /**
     * Signed deliveries verify through the shared `paypal` endpoint and
     * unsigned ones don't; the sibling's own endpoint refuses both.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function test_handle_webhook_verifies_signed_requests_and_rejects_unsigned_ones(): void
    {
        $shared = $this->app->make( \ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry::class )->get( PayPalWalletGateway::KEY );

        $this->assertInstanceOf( PayPalWalletGateway::class, $shared );
        $this->assertContains( $this->gatewayKey(), $shared->webhookGatewayKeys(), 'The shared endpoint must reconcile this gateway\'s payments.' );

        $verified = $shared->handleWebhook( $this->signedWebhookRequest() );

        $this->assertTrue( $verified->verified, 'A signed delivery must verify at the shared endpoint (got errorCode: ' . ( $verified->errorCode ?? 'null' ) . ').' );
        $this->assertNotNull( $verified->eventId );

        $unverified = $shared->handleWebhook( $this->unsignedWebhookRequest() );

        $this->assertFalse( $unverified->verified, 'An unsigned delivery must not verify.' );
        $this->assertNotNull( $unverified->errorCode );

        foreach ( [ $this->signedWebhookRequest(), $this->unsignedWebhookRequest() ] as $request ) {
            $own = $this->gateway()->handleWebhook( $request );

            $this->assertFalse( $own->verified, 'A sibling must not verify deliveries at its own endpoint.' );
            $this->assertSame( 'use_paypal_endpoint', $own->errorCode );
        }
    }
}
