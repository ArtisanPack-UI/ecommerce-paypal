<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Support\PayPalCurrencies;

it( 'knows which methods take a currency', function ( string $method, string $currency, bool $supported ): void {
    expect( PayPalCurrencies::supports( $method, $currency ) )->toBe( $supported );
} )->with( [
    'PayPal in HUF'        => [ 'paypal', 'HUF', true ],
    'card in HUF'          => [ 'card', 'HUF', true ],
    'Venmo in HUF'         => [ 'venmo', 'HUF', false ],
    'Venmo in USD'         => [ 'venmo', 'USD', true ],
    'card in BRL'          => [ 'card', 'BRL', false ],
    'Apple Pay in TWD'     => [ 'apple_pay', 'TWD', true ],
    'Google Pay in CNY'    => [ 'google_pay', 'CNY', false ],
    'PayPal in lower case' => [ 'paypal', 'usd', true ],
    'unknown currency'     => [ 'paypal', 'XYZ', false ],
    'unknown method'       => [ 'cash', 'USD', false ],
] );

it( 'flags the currencies PayPal takes without decimals', function (): void {
    expect( PayPalCurrencies::decimals( 'JPY', 0 ) )->toBe( 0 )
        ->and( PayPalCurrencies::decimals( 'HUF', 2 ) )->toBe( 0 )
        ->and( PayPalCurrencies::decimals( 'TWD', 2 ) )->toBe( 0 )
        ->and( PayPalCurrencies::decimals( 'USD', 2 ) )->toBe( 2 );
} );

it( 'lets a host adjust a method\'s currencies', function (): void {
    addFilter( 'ap.ecommerce.paypal.supportedCurrencies', static fn ( array $currencies, string $method ): array => 'card' === $method ? [ ...$currencies, 'brl' ] : $currencies, 10, 2 );

    expect( PayPalCurrencies::supports( 'card', 'BRL' ) )->toBeTrue()
        ->and( PayPalCurrencies::supports( 'apple_pay', 'CNY' ) )->toBeFalse();
} );
