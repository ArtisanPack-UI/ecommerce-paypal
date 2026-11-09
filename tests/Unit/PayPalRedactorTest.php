<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Support\PayPalRedactor;

it( 'redacts credentials, tokens, card data, and payer personal data', function (): void {
    $redacted = PayPalRedactor::redact( [
        'access_token'   => 'A21AA-secret',
        'id_token'       => 'eyJ-secret',
        'client_secret'  => 'shh',
        'payment_source' => [
            'card'   => [ 'name' => 'John Doe', 'last_digits' => '1111', 'expiry' => '2030-12', 'brand' => 'VISA' ],
            'paypal' => [ 'email_address' => 'buyer@example.com', 'name' => [ 'given_name' => 'John', 'surname' => 'Doe' ] ],
        ],
        'payer'          => [ 'email_address' => 'buyer@example.com', 'payer_id' => 'QYR5Z8XDVJNXQ' ],
        'purchase_units' => [
            [
                'items'    => [ [ 'name' => 'Blue T-shirt', 'quantity' => '1' ] ],
                'shipping' => [ 'name' => [ 'full_name' => 'John Doe' ], 'address' => [ 'address_line_1' => '1 Main St' ] ],
            ],
        ],
    ] );

    expect( $redacted['access_token'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['id_token'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['client_secret'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_source']['card'] )->each->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_source']['paypal']['email_address'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_source']['paypal']['name'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payer']['email_address'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payer']['payer_id'] )->toBe( 'QYR5Z8XDVJNXQ' )
        ->and( $redacted['purchase_units'][0]['items'][0]['name'] )->toBe( 'Blue T-shirt' )
        ->and( $redacted['purchase_units'][0]['shipping']['name'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['purchase_units'][0]['shipping']['address'] )->toBe( PayPalRedactor::MASK );
} );

it( 'matches keys case-insensitively', function (): void {
    expect( PayPalRedactor::redact( [ 'Access_Token' => 'x' ] ) )->toBe( [ 'Access_Token' => PayPalRedactor::MASK ] );
} );

it( 'redacts sensitive headers', function (): void {
    expect( PayPalRedactor::redactHeaders( [ 'Authorization' => 'Bearer x', 'PayPal-Request-Id' => 'abc' ] ) )
        ->toBe( [ 'Authorization' => PayPalRedactor::MASK, 'PayPal-Request-Id' => 'abc' ] );
} );
