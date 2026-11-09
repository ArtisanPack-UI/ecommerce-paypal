<?php

/**
 * Currencies PayPal accepts, per payment method.
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

/**
 * The currency allowlists that decide where each PayPal method is offered
 * (spec §6.2), and PayPal's decimal rules (spec §6.3).
 *
 * The lists follow PayPal's currency code reference and the per-method
 * availability pages (advanced card processing, Apple Pay, Google Pay,
 * Venmo, Pay Later). Buyer-country limits (Venmo is US-only, Pay Later
 * varies by country) are applied by the JS SDK, not here. A host can
 * narrow or widen any list through `ap.ecommerce.paypal.supportedCurrencies`
 * `(array $currencies, string $method)`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalCurrencies
{
    /**
     * Every currency PayPal checkout accepts.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PAYPAL = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS', 'JPY', 'MYR',
        'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * Currencies for advanced card processing.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const CARD = [
        'AUD', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'JPY', 'NOK', 'NZD',
        'PLN', 'SEK', 'SGD', 'USD',
    ];

    /**
     * Currencies for Apple Pay through PayPal.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const APPLE_PAY = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY',
        'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD',
    ];

    /**
     * Currencies for Google Pay through PayPal.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const GOOGLE_PAY = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY',
        'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD', 'THB', 'TWD', 'USD',
    ];

    /**
     * Currencies for Venmo (inside the PayPal buttons).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VENMO = [ 'USD' ];

    /**
     * Currencies for Pay Later (inside the PayPal buttons).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const PAY_LATER = [ 'AUD', 'EUR', 'GBP', 'USD' ];

    /**
     * Currencies PayPal takes without decimals, even where ISO 4217 has them.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const ZERO_DECIMAL = [ 'HUF', 'JPY', 'TWD' ];

    /**
     * Method keys.
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const METHODS = [
        'paypal'     => self::PAYPAL,
        'card'       => self::CARD,
        'apple_pay'  => self::APPLE_PAY,
        'google_pay' => self::GOOGLE_PAY,
        'venmo'      => self::VENMO,
        'pay_later'  => self::PAY_LATER,
    ];

    /**
     * The currencies a method accepts, after the host's filter.
     *
     * @since 1.0.0
     *
     * @param  string  $method  Method key (`paypal`, `card`, `apple_pay`, `google_pay`, `venmo`, `pay_later`).
     *
     * @return array<int, string>
     */
    public static function for( string $method ): array
    {
        $currencies = self::METHODS[ $method ] ?? [];

        if ( function_exists( 'applyFilters' ) ) {
            $filtered   = applyFilters( 'ap.ecommerce.paypal.supportedCurrencies', $currencies, $method );
            $currencies = is_array( $filtered ) ? $filtered : $currencies;
        }

        return array_values( array_unique( array_map( static fn ( mixed $code ): string => strtoupper( (string) $code ), $currencies ) ) );
    }

    /**
     * Whether a method accepts a currency.
     *
     * @since 1.0.0
     *
     * @param  string  $method    Method key.
     * @param  string  $currency  ISO 4217 code.
     *
     * @return bool
     */
    public static function supports( string $method, string $currency ): bool
    {
        return in_array( strtoupper( $currency ), self::for( $method ), true );
    }

    /**
     * How many decimals PayPal takes for a currency.
     *
     * @since 1.0.0
     *
     * @param  string  $currency  ISO 4217 code.
     * @param  int     $isoSubunit  The currency's ISO 4217 minor unit.
     *
     * @return int
     */
    public static function decimals( string $currency, int $isoSubunit ): int
    {
        return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : $isoSubunit;
    }
}
