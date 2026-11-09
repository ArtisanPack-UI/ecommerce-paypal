<?php

/**
 * The `paypal` gateway.
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

use ArtisanPackUI\Ecommerce\Contracts\SharesWebhookEndpoint;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Webhooks\WebhookVerifier;
use Illuminate\Http\Request;

/**
 * PayPal buttons: PayPal, Pay Later, and Venmo.
 *
 * Also the gateway PayPal's webhooks are posted to
 * (`ecommerce/webhooks/paypal`). Through {@see SharesWebhookEndpoint} it
 * vouches for the sibling keys, so the engine reconciles card, Apple Pay,
 * and Google Pay checkouts from the same events (spec D4, engine #207).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalWalletGateway extends PayPalGateway implements SharesWebhookEndpoint
{
    /**
     * The gateway key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const KEY = 'paypal';

    /**
     * The sibling gateways whose sessions this gateway's webhooks report on.
     *
     * @since 1.0.0
     *
     * @var array<int, class-string<PayPalGateway>>
     */
    public const SIBLINGS = [
        PayPalCardGateway::class,
        PayPalApplePayGateway::class,
        PayPalGooglePayGateway::class,
    ];

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
        return __( 'PayPal' );
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
        return 'paypal';
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
        return 'paypal';
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
        return 'paypal-buttons';
    }

    /**
     * PayPal wallets can be saved when vaulting is on.
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
     * Its own key plus the sibling keys.
     *
     * Every sibling is listed, not just the enabled ones: a card order
     * placed before cards were switched off still gets its pending capture
     * settled and its dashboard refunds reconciled. The engine drops any
     * key that isn't registered.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function webhookGatewayKeys(): array
    {
        return [ self::KEY, ...array_map( static fn ( string $sibling ): string => $sibling::KEY, self::SIBLINGS ) ];
    }

    /**
     * Handles PayPal's webhooks.
     *
     * Every delivery is signature-checked by {@see WebhookVerifier} first
     * (spec §8.1); one that fails is refused with the verifier's code. A
     * verified delivery is returned with its event type, id, and payload;
     * the payment outcome each event carries is mapped in #12.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return WebhookResult
     */
    public function handleWebhook( Request $request ): WebhookResult
    {
        return app( WebhookVerifier::class )->verify( $request );
    }
}
