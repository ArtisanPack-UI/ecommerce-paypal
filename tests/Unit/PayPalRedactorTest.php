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

it( 'masks the rejected values echoed in error details', function (): void {
    $redacted = PayPalRedactor::redact( [
        'name'    => 'UNPROCESSABLE_ENTITY',
        'details' => [ [ 'field' => '/payment_source/card/number', 'value' => '4111111111111111', 'issue' => 'INVALID_PARAMETER_VALUE' ] ],
        'amount'  => [ 'value' => '10.00' ],
    ] );

    expect( $redacted['details'][0]['value'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['details'][0]['issue'] )->toBe( 'INVALID_PARAMETER_VALUE' )
        ->and( $redacted['amount']['value'] )->toBe( '10.00' );
} );

it( 'masks saved-payment token ids', function (): void {
    $redacted = PayPalRedactor::redactVault( [
        'id'             => 'TOKEN-1',
        'payment_tokens' => [ [ 'id' => 'TOKEN-2', 'customer' => [ 'id' => 'customer_1' ] ] ],
        'links'          => [ [ 'href' => 'https://api-m.paypal.com/v3/vault/payment-tokens/TOKEN-1?x=1' ] ],
    ] );

    expect( $redacted['id'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_tokens'][0]['id'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_tokens'][0]['customer']['id'] )->toBe( 'customer_1' )
        ->and( $redacted['links'][0]['href'] )->toBe( 'https://api-m.paypal.com/v3/vault/payment-tokens/' . PayPalRedactor::MASK . '?x=1' )
        ->and( PayPalRedactor::redact( [ 'card' => [ 'vault_id' => 'TOKEN-3' ] ] )['card']['vault_id'] )->toBe( PayPalRedactor::MASK )
        ->and( PayPalRedactor::redact( [ 'id' => 'ORDER-1' ] )['id'] )->toBe( 'ORDER-1' );
} );

it( 'masks token ids in paths', function ( string $path, string $expected ): void {
    expect( PayPalRedactor::redactPath( $path ) )->toBe( $expected );
} )->with( [
    'payment token' => [ '/v3/vault/payment-tokens/TOKEN-1', '/v3/vault/payment-tokens/' . PayPalRedactor::MASK ],
    'setup token'   => [ '/v3/vault/setup-tokens/SETUP-1', '/v3/vault/setup-tokens/' . PayPalRedactor::MASK ],
    'list'          => [ '/v3/vault/payment-tokens', '/v3/vault/payment-tokens' ],
    'order'         => [ '/v2/checkout/orders/ORDER-1', '/v2/checkout/orders/ORDER-1' ],
] );

it( 'masks the vault id in an order payment source', function (): void {
    $redacted = PayPalRedactor::redact( [
        'id'             => 'ORDER-1',
        'payment_source' => [ 'card' => [ 'attributes' => [ 'vault' => [ 'id' => 'TOKEN-1', 'status' => 'VAULTED', 'customer' => [ 'id' => 'customer_1' ] ] ] ] ],
    ] );

    expect( $redacted['payment_source']['card']['attributes']['vault']['id'] )->toBe( PayPalRedactor::MASK )
        ->and( $redacted['payment_source']['card']['attributes']['vault']['status'] )->toBe( 'VAULTED' )
        ->and( $redacted['payment_source']['card']['attributes']['vault']['customer']['id'] )->toBe( 'customer_1' )
        ->and( $redacted['id'] )->toBe( 'ORDER-1' );
} );
