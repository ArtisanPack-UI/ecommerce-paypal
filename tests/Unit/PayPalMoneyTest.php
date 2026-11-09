<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use Money\Currency;
use Money\Money;

it( 'converts engine money to PayPal decimal strings', function ( int $minor, string $currency, string $expected ): void {
    expect( PayPalMoney::toPayPal( new Money( $minor, new Currency( $currency ) ) ) )
        ->toBe( [ 'currency_code' => $currency, 'value' => $expected ] );
} )->with( [
    'USD'                => [ 10050, 'USD', '100.50' ],
    'USD under a unit'   => [ 5, 'USD', '0.05' ],
    'USD zero'           => [ 0, 'USD', '0.00' ],
    'EUR'                => [ 199, 'EUR', '1.99' ],
    'JPY (no decimals)'  => [ 12345, 'JPY', '12345' ],
    'HUF rounds up'      => [ 123456, 'HUF', '1235' ],
    'HUF rounds down'    => [ 123449, 'HUF', '1234' ],
    'HUF half rounds up' => [ 123450, 'HUF', '1235' ],
    'TWD whole'          => [ 50000, 'TWD', '500' ],
    'negative'           => [ -1050, 'USD', '-10.50' ],
] );

it( 'converts PayPal amounts back to engine money', function ( array $amount, int $minor ): void {
    $money = PayPalMoney::fromPayPal( $amount, new Currency( $amount['currency_code'] ) );

    expect( (int) $money->getAmount() )->toBe( $minor )
        ->and( $money->getCurrency()->getCode() )->toBe( $amount['currency_code'] );
} )->with( [
    'USD'             => [ [ 'currency_code' => 'USD', 'value' => '100.50' ], 10050 ],
    'USD one decimal' => [ [ 'currency_code' => 'USD', 'value' => '1.5' ], 150 ],
    'USD whole'       => [ [ 'currency_code' => 'USD', 'value' => '7' ], 700 ],
    'JPY'             => [ [ 'currency_code' => 'JPY', 'value' => '12345' ], 12345 ],
    'HUF whole'       => [ [ 'currency_code' => 'HUF', 'value' => '1235' ], 123500 ],
    'HUF zero cents'  => [ [ 'currency_code' => 'HUF', 'value' => '1235.00' ], 123500 ],
    'zero'            => [ [ 'currency_code' => 'EUR', 'value' => '0.00' ], 0 ],
    'negative'        => [ [ 'currency_code' => 'USD', 'value' => '-2.25' ], -225 ],
] );

it( 'trusts the PayPal currency when none is expected', function (): void {
    expect( PayPalMoney::fromPayPal( [ 'currency_code' => 'gbp', 'value' => '3.00' ] )->getCurrency()->getCode() )->toBe( 'GBP' );
} );

it( 'rejects malformed PayPal amounts', function ( array $amount, ?string $expected ): void {
    PayPalMoney::fromPayPal( $amount, null === $expected ? null : new Currency( $expected ) );
} )->with( [
    'no currency'        => [ [ 'value' => '1.00' ], null ],
    'not a number'       => [ [ 'currency_code' => 'USD', 'value' => '1,00' ], null ],
    'too many decimals'  => [ [ 'currency_code' => 'USD', 'value' => '1.001' ], null ],
    'JPY with decimals'  => [ [ 'currency_code' => 'JPY', 'value' => '100.5' ], null ],
    'HUF with decimals'  => [ [ 'currency_code' => 'HUF', 'value' => '1235.50' ], null ],
    'TWD with decimals'  => [ [ 'currency_code' => 'TWD', 'value' => '500.01' ], null ],
    'currency mismatch'  => [ [ 'currency_code' => 'EUR', 'value' => '1.00' ], 'USD' ],
] )->throws( InvalidArgumentException::class );

it( 'reports PayPal decimals per currency', function ( string $currency, int $decimals ): void {
    expect( PayPalMoney::decimals( new Currency( $currency ) ) )->toBe( $decimals );
} )->with( [
    [ 'USD', 2 ],
    [ 'JPY', 0 ],
    [ 'HUF', 0 ],
    [ 'TWD', 0 ],
    [ 'EUR', 2 ],
] );

it( 'round-trips units', function (): void {
    $currency = new Currency( 'HUF' );

    expect( PayPalMoney::toUnits( new Money( 123456, $currency ) ) )->toBe( 1235 )
        ->and( (int) PayPalMoney::fromUnits( 1235, $currency )->getAmount() )->toBe( 123500 )
        ->and( PayPalMoney::unitsToPayPal( 1235, $currency ) )->toBe( [ 'currency_code' => 'HUF', 'value' => '1235' ] );
} );
