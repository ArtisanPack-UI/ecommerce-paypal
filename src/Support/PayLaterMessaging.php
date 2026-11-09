<?php

/**
 * Pay Later messaging gates and settings.
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

use ArtisanPackUI\Ecommerce\Services\StoreCurrencies;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use Money\Currency;
use Money\Money;

/**
 * Decides where PayPal's Pay Later messages are shown (spec §9.7) and
 * builds the client config `paypal-checkout.js`'s `mountMessages()` needs.
 *
 * A message renders only when all of these hold:
 *
 * - PayPal is active (enabled, with credentials);
 * - the `paypal` method and its Pay Later option are on;
 * - `pay_later_messaging.{product|cart|checkout}` is on for the placement;
 * - the currency is one Pay Later supports;
 * - the amount is above zero.
 *
 * Whether the buyer is eligible is decided by PayPal, which renders nothing
 * for an ineligible buyer.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayLaterMessaging
{
    /**
     * The `pay_later_messaging` config key for each placement.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const CONFIG_KEYS = [
        'product'  => 'product',
        'cart'     => 'cart',
        'payment'  => 'checkout',
        'checkout' => 'checkout',
    ];

    /**
     * The PayPal SDK placement for each of ours.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const SDK_PLACEMENTS = [
        'product'  => 'product',
        'cart'     => 'cart',
        'payment'  => 'payment',
        'checkout' => 'payment',
    ];

    /**
     * Whether a message may render at `$placement` for `$amount`.
     *
     * @since 1.0.0
     *
     * @param  string      $placement  `product`, `cart`, or `payment` (`checkout`).
     * @param  Money|null  $amount     Amount.
     *
     * @return bool
     */
    public static function enabledFor( string $placement, ?Money $amount ): bool
    {
        return null !== $amount
            && $amount->isPositive()
            && self::placementEnabled( $placement, $amount->getCurrency()->getCode() );
    }

    /**
     * Every gate but the amount: whether messages may show at `$placement`
     * in `$currency` at all.
     *
     * @since 1.0.0
     *
     * @param  string  $placement  `product`, `cart`, or `payment` (`checkout`).
     * @param  string  $currency   ISO currency code.
     *
     * @return bool
     */
    public static function placementEnabled( string $placement, string $currency ): bool
    {
        $key = self::CONFIG_KEYS[ $placement ] ?? null;

        return null !== $key
            && EcommercePayPalServiceProvider::gatewaysActive()
            && (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.enabled', false )
            && (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.pay_later', false )
            && (bool) config( 'artisanpack.ecommerce-paypal.pay_later_messaging.' . $key, false )
            && PayPalCurrencies::supports( 'pay_later', strtoupper( $currency ) );
    }

    /**
     * `$amount` as engine money: a `Money`, or minor units in `$currency`
     * (the store's base currency when null). Null for anything else.
     *
     * @since 1.0.0
     *
     * @param  mixed        $amount    Amount.
     * @param  string|null  $currency  Currency for a minor-unit amount.
     *
     * @return Money|null
     */
    public static function money( mixed $amount, ?string $currency = null ): ?Money
    {
        if ( $amount instanceof Money ) {
            return $amount;
        }

        if ( ! is_int( $amount ) && ! ( is_string( $amount ) && 1 === preg_match( '/^\d+$/', $amount ) ) ) {
            return null;
        }

        $currency = strtoupper( trim( (string) ( $currency ?? '' ) ) );
        $currency = '' === $currency ? strtoupper( app( StoreCurrencies::class )->base() ) : $currency;

        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
            return null;
        }

        return new Money( (int) $amount, new Currency( $currency ) );
    }

    /**
     * The settings the message is mounted with.
     *
     * @since 1.0.0
     *
     * @param  string  $placement  Placement.
     * @param  Money   $amount     Amount.
     *
     * @return array{config: array<string, mixed>, amount: string, placement: string}
     */
    public static function settings( string $placement, Money $amount ): array
    {
        $sdk = PayPalGateway::sdkConfig( $amount->getCurrency()->getCode() );

        return [
            'config'    => [ 'publishable_key' => $sdk['publishable_key'], 'options' => $sdk['options'] ],
            'amount'    => PayPalMoney::toPayPal( $amount )['value'],
            'placement' => self::SDK_PLACEMENTS[ $placement ] ?? 'payment',
        ];
    }
}
