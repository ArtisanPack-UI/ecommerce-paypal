<?php

/**
 * Money conversion for PayPal.
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

use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;

/**
 * Converts between engine `Money` (integer minor units, ISO 4217 subunits)
 * and PayPal money objects (`{ currency_code, value }` decimal strings),
 * following PayPal's decimal rules (spec §6.3).
 *
 * HUF and TWD have two ISO decimals but PayPal takes them whole, so those
 * amounts are rounded half-up to a whole unit on the way out. The rounding
 * difference is what {@see \ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder}
 * reconciles so PayPal's totals still match the engine's.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalMoney
{
    /**
     * ISO 4217 subunits.
     *
     * @since 1.0.0
     *
     * @var ISOCurrencies|null
     */
    private static ?ISOCurrencies $currencies = null;

    /**
     * Converts engine money to a PayPal money object.
     *
     * @since 1.0.0
     *
     * @param  Money  $money  Engine money.
     *
     * @return array{currency_code: string, value: string}
     */
    public static function toPayPal( Money $money ): array
    {
        return self::unitsToPayPal( self::toUnits( $money ), $money->getCurrency() );
    }

    /**
     * Converts a PayPal money object to engine money.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $amount    PayPal `{ currency_code, value }`.
     * @param  Currency|null         $currency  Expected currency; null trusts the object's.
     *
     * @throws InvalidArgumentException When the value is malformed or the currency differs.
     *
     * @return Money
     */
    public static function fromPayPal( array $amount, ?Currency $currency = null ): Money
    {
        $code  = strtoupper( (string) ( $amount['currency_code'] ?? '' ) );
        $value = trim( (string) ( $amount['value'] ?? '' ) );

        if ( '' === $code ) {
            throw new InvalidArgumentException( 'The PayPal amount has no currency.' );
        }

        if ( null !== $currency && $currency->getCode() !== $code ) {
            throw new InvalidArgumentException( sprintf( 'Expected a %s amount from PayPal, got %s.', $currency->getCode(), $code ) );
        }

        if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
            throw new InvalidArgumentException( sprintf( 'The PayPal amount "%s" is not a decimal number.', $value ) );
        }

        $currency             = new Currency( $code );
        $subunit              = self::subunit( $currency );
        $negative             = str_starts_with( $value, '-' );
        [ $whole, $fraction ] = array_pad( explode( '.', ltrim( $value, '-' ), 2 ), 2, '' );

        if ( strlen( rtrim( $fraction, '0' ) ) > self::decimals( $currency ) ) {
            throw new InvalidArgumentException( sprintf( 'The PayPal amount "%s" has more decimals than %s allows.', $value, $code ) );
        }

        $minor = ltrim( $whole . str_pad( substr( $fraction, 0, $subunit ), $subunit, '0' ), '0' );
        $minor = '' === $minor ? '0' : $minor;

        return new Money( ( $negative && '0' !== $minor ? '-' : '' ) . $minor, $currency );
    }

    /**
     * An amount in PayPal's smallest unit for its currency (cents for USD,
     * whole forints for HUF), rounded half-up when PayPal takes fewer
     * decimals than ISO 4217.
     *
     * @since 1.0.0
     *
     * @param  Money  $money  Engine money.
     *
     * @return int
     */
    public static function toUnits( Money $money ): int
    {
        $shift = self::subunit( $money->getCurrency() ) - self::decimals( $money->getCurrency() );
        $minor = (int) $money->getAmount();

        if ( 0 === $shift ) {
            return $minor;
        }

        $factor = 10 ** $shift;
        $sign   = $minor < 0 ? -1 : 1;

        return $sign * intdiv( abs( $minor ) + intdiv( $factor, 2 ), $factor );
    }

    /**
     * Builds engine money from PayPal units.
     *
     * @since 1.0.0
     *
     * @param  int       $units     Amount in PayPal's smallest unit.
     * @param  Currency  $currency  Currency.
     *
     * @return Money
     */
    public static function fromUnits( int $units, Currency $currency ): Money
    {
        return new Money( $units * ( 10 ** ( self::subunit( $currency ) - self::decimals( $currency ) ) ), $currency );
    }

    /**
     * A PayPal money object from PayPal units.
     *
     * @since 1.0.0
     *
     * @param  int       $units     Amount in PayPal's smallest unit.
     * @param  Currency  $currency  Currency.
     *
     * @return array{currency_code: string, value: string}
     */
    public static function unitsToPayPal( int $units, Currency $currency ): array
    {
        return [
            'currency_code' => $currency->getCode(),
            'value'         => self::format( $units, self::decimals( $currency ) ),
        ];
    }

    /**
     * How many decimals PayPal takes for a currency.
     *
     * @since 1.0.0
     *
     * @param  Currency  $currency  Currency.
     *
     * @return int
     */
    public static function decimals( Currency $currency ): int
    {
        return PayPalCurrencies::decimals( $currency->getCode(), self::subunit( $currency ) );
    }

    /**
     * The ISO 4217 minor unit for a currency (2 when unknown).
     *
     * @since 1.0.0
     *
     * @param  Currency  $currency  Currency.
     *
     * @return int
     */
    public static function subunit( Currency $currency ): int
    {
        self::$currencies ??= new ISOCurrencies();

        return self::$currencies->contains( $currency ) ? self::$currencies->subunitFor( $currency ) : 2;
    }

    /**
     * Formats units as a decimal string.
     *
     * @since 1.0.0
     *
     * @param  int  $units     Amount in the smallest unit.
     * @param  int  $decimals  Decimal places.
     *
     * @return string
     */
    private static function format( int $units, int $decimals ): string
    {
        $sign   = $units < 0 ? '-' : '';
        $digits = (string) abs( $units );

        if ( 0 === $decimals ) {
            return $sign . $digits;
        }

        $digits = str_pad( $digits, $decimals + 1, '0', STR_PAD_LEFT );

        return $sign . substr( $digits, 0, -$decimals ) . '.' . substr( $digits, -$decimals );
    }
}
