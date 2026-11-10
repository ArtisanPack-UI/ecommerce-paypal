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
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalEventMapper;
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
     * verified delivery is mapped to the engine's payment outcome by
     * {@see PayPalEventMapper} (spec §8.2), which the engine reconciles
     * across the whole gateway family. Notes, refunds, and disputes are
     * handled afterwards, once the engine has deduplicated the delivery
     * ({@see \ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor}).
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @throws PayPalApiException When PayPal can't be reached to confirm a failed payment (PayPal redelivers).
     *
     * @return WebhookResult
     */
    public function handleWebhook( Request $request ): WebhookResult
    {
        return app( PayPalEventMapper::class )->map( app( WebhookVerifier::class )->verify( $request ) );
    }

    /**
     * PayPal and Venmo, plus `card` while card fields are switched off
     * (the buttons then show PayPal's own guest-card button; with card
     * fields on, cards are paid through `paypal-card`).
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    protected function acceptedSources(): array
    {
        $sources = [ 'paypal', 'venmo' ];

        if ( ! (bool) config( 'artisanpack.ecommerce-paypal.methods.card.enabled', false ) ) {
            $sources[] = 'card';
        }

        return $sources;
    }

    /**
     * The buttons' style and the Pay Later and Venmo switches.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function driverOptions(): array
    {
        $style = (array) config( 'artisanpack.ecommerce-paypal.methods.paypal.button', [] );

        return [
            'button'    => array_intersect_key( $style, array_flip( [ 'layout', 'color', 'shape', 'label', 'height', 'tagline' ] ) ),
            'pay_later' => (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.pay_later', false ),
            'venmo'     => (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.venmo', false ),
        ];
    }
}
