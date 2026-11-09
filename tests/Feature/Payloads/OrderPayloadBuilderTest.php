<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use Illuminate\Support\Facades\Route;
use Tests\Support\CartBuilder;

/**
 * A PayPal decimal string as integer units.
 *
 * @param  array<string, string>|null  $amount  PayPal money object.
 *
 * @return int
 */
function paypalUnits( ?array $amount ): int
{
    return null === $amount ? 0 : (int) str_replace( '.', '', $amount['value'] );
}

/**
 * Asserts the purchase unit adds up the way PayPal validates it.
 *
 * @param  array<string, mixed>  $unit  Purchase unit.
 *
 * @return void
 */
function assertPayPalBreakdownAddsUp( array $unit ): void
{
    $breakdown = $unit['amount']['breakdown'];
    $items     = array_sum( array_map( fn ( array $item ): int => paypalUnits( $item['unit_amount'] ) * (int) $item['quantity'], $unit['items'] ?? [] ) );

    expect( paypalUnits( $breakdown['item_total'] ) )->toBe( $items );

    $sum = paypalUnits( $breakdown['item_total'] )
        + paypalUnits( $breakdown['shipping'] ?? null )
        + paypalUnits( $breakdown['handling'] ?? null )
        + paypalUnits( $breakdown['tax_total'] ?? null )
        - paypalUnits( $breakdown['discount'] ?? null )
        - paypalUnits( $breakdown['shipping_discount'] ?? null );

    expect( $sum )->toBe( paypalUnits( $unit['amount'] ) );

    foreach ( $breakdown as $value ) {
        expect( $value['currency_code'] )->toBe( $unit['amount']['currency_code'] )
            ->and( paypalUnits( $value ) )->toBeGreaterThanOrEqual( 0 );
    }
}

/**
 * Builds a payload.
 *
 * @param  Cart                  $cart     Cart.
 * @param  class-string<PayPalGateway>  $gateway  Gateway class.
 * @param  array<string, mixed>  $context  Context.
 *
 * @return array<string, mixed>
 */
function paypalPayload( Cart $cart, string $gateway = PayPalWalletGateway::class, array $context = [] ): array
{
    return app( OrderPayloadBuilder::class )->build( $cart, app( $gateway ), $context );
}

it( 'builds a valid breakdown', function ( array $lines, array $attributes, string $total, array $expectedBreakdown ): void {
    $unit = paypalPayload( CartBuilder::make( $lines, $attributes ) )['purchase_units'][0];

    assertPayPalBreakdownAddsUp( $unit );

    expect( $unit['amount']['value'] )->toBe( $total );

    foreach ( $expectedBreakdown as $key => $value ) {
        expect( $unit['amount']['breakdown'][ $key ]['value'] ?? null )->toBe( $value );
    }
} )->with( [
    'USD with shipping and tax' => [
        [ [ 'Shirt', 2500, 2 ], [ 'Hat', 1000, 1 ] ],
        [ 'shipping_amount' => 599, 'tax_amount' => 420 ],
        '70.19',
        [ 'item_total' => '60.00', 'shipping' => '5.99', 'tax_total' => '4.20' ],
    ],
    'EUR with a discount' => [
        [ [ 'Mug', 1250, 4 ] ],
        [ 'currency' => 'EUR', 'discount_amount' => 500 ],
        '45.00',
        [ 'item_total' => '50.00', 'discount' => '5.00' ],
    ],
    'GBP' => [
        [ [ 'Book', 899, 1 ] ],
        [ 'currency' => 'GBP', 'shipping_amount' => 300 ],
        '11.99',
        [ 'item_total' => '8.99', 'shipping' => '3.00' ],
    ],
    'JPY ¥12,345 with a ¥1,000 coupon' => [
        [ [ 'Tea set', 12345, 1 ] ],
        [ 'currency' => 'JPY', 'discount_amount' => 1000 ],
        '11345',
        [ 'item_total' => '12345', 'discount' => '1000' ],
    ],
    'HUF rounding drift goes to handling' => [
        [ [ 'Paprika', 33333, 3 ] ],
        [ 'currency' => 'HUF' ],
        '1000',
        [ 'item_total' => '999', 'handling' => '1' ],
    ],
    'HUF rounding drift goes to the discount' => [
        [ [ 'Paprika', 33367, 3 ] ],
        [ 'currency' => 'HUF' ],
        '1001',
        [ 'item_total' => '1002', 'discount' => '1' ],
    ],
    'TWD whole units' => [
        [ [ 'Lamp', 150000, 1 ] ],
        [ 'currency' => 'TWD', 'shipping_amount' => 6000 ],
        '1560',
        [ 'item_total' => '1500', 'shipping' => '60' ],
    ],
    'discount larger than the items spills onto shipping' => [
        [ [ 'Sticker', 500, 1 ] ],
        [ 'discount_amount' => 700, 'shipping_amount' => 1000 ],
        '8.00',
        [ 'item_total' => '5.00', 'discount' => '5.00', 'shipping_discount' => '2.00', 'shipping' => '10.00' ],
    ],
    'free shipping' => [
        [ [ 'Shirt', 2500, 1 ] ],
        [ 'shipping_amount' => 0 ],
        '25.00',
        [ 'item_total' => '25.00', 'shipping' => null ],
    ],
    'tax-inclusive pricing' => [
        [ [ 'Shirt', 1200, 1 ] ],
        [ 'currency' => 'EUR', 'tax_amount' => 200, 'total_amount' => 1200, 'meta' => [ 'tax' => [ 'prices_include_tax' => true ] ] ],
        '12.00',
        [ 'item_total' => '12.00', 'tax_total' => null ],
    ],
    'a total filter that lowered the total' => [
        [ [ 'Shirt', 2000, 1 ] ],
        [ 'shipping_amount' => 500, 'total_amount' => 2300 ],
        '23.00',
        [ 'item_total' => '20.00', 'discount' => '2.00' ],
    ],
] );

it( 'keeps every line of a 100+ item cart and still adds up', function (): void {
    $lines = array_map( fn ( int $i ): array => [ 'Item ' . $i, 101 + $i, 1 + ( $i % 3 ) ], range( 1, 120 ) );
    $unit  = paypalPayload( CartBuilder::make( $lines, [ 'shipping_amount' => 1234, 'tax_amount' => 987, 'discount_amount' => 555 ] ) )['purchase_units'][0];

    expect( $unit['items'] )->toHaveCount( 120 );
    assertPayPalBreakdownAddsUp( $unit );
} );

it( 'describes the line items', function (): void {
    $unit = paypalPayload( CartBuilder::make( [
        [ str_repeat( 'A', 200 ), 1999, 2, 'simple', 'SKU-1' ],
        [ 'E-book', 999, 1, 'digital', 'BOOK', 'BOOK-PDF' ],
    ], [ 'shipping_address' => [ 'address1' => '1 Main St', 'city' => 'Austin', 'country_code' => 'US' ] ] ) )['purchase_units'][0];

    expect( $unit['items'][0] )->toBe( [
        'name'        => str_repeat( 'A', 127 ),
        'sku'         => 'SKU-1',
        'quantity'    => '2',
        'unit_amount' => [ 'currency_code' => 'USD', 'value' => '19.99' ],
        'category'    => 'PHYSICAL_GOODS',
    ] )
        ->and( $unit['items'][1]['sku'] )->toBe( 'BOOK-PDF' )
        ->and( $unit['items'][1]['category'] )->toBe( 'DIGITAL_GOODS' );
} );

it( 'identifies the cart and the attempt', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.soft_descriptor' => 'ACME STORE ONLINE SHOP!!' ] );

    $cart  = CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] );
    $first = paypalPayload( $cart, context: [ 'attempt' => 3 ] )['purchase_units'][0];

    expect( $first['custom_id'] )->toBe( '42' )
        ->and( $first['invoice_id'] )->toBe( 'cart-42-3' )
        ->and( $first['soft_descriptor'] )->toBe( 'ACME STORE ONLINE SHOP' );

    $a = paypalPayload( $cart )['purchase_units'][0]['invoice_id'];
    $b = paypalPayload( $cart )['purchase_units'][0]['invoice_id'];

    expect( $a )->toStartWith( 'cart-42-' )->not->toBe( $b );
} );

it( 'sends the address for physical carts and no shipping for digital ones', function (): void {
    $physical = paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ], [ 'shipping_address' => [
        'first_name'   => 'Jane',
        'last_name'    => 'Doe',
        'address1'     => '1 Main St',
        'address2'     => 'Apt 2',
        'city'         => 'Austin',
        'region'       => 'Texas',
        'region_code'  => 'TX',
        'postal_code'  => '78701',
        'country_code' => 'us',
    ] ] ) );

    expect( $physical['purchase_units'][0]['shipping'] )->toBe( [
        'type'    => 'SHIPPING',
        'name'    => [ 'full_name' => 'Jane Doe' ],
        'address' => [
            'address_line_1' => '1 Main St',
            'address_line_2' => 'Apt 2',
            'admin_area_2'   => 'Austin',
            'admin_area_1'   => 'TX',
            'postal_code'    => '78701',
            'country_code'   => 'US',
        ],
    ] )
        ->and( $physical['payment_source']['paypal']['experience_context']['shipping_preference'] )->toBe( 'SET_PROVIDED_ADDRESS' );

    $digital = paypalPayload( CartBuilder::make( [ [ 'E-book', 999, 1, 'digital' ] ] ) );

    expect( $digital['purchase_units'][0] )->not->toHaveKey( 'shipping' )
        ->and( $digital['payment_source']['paypal']['experience_context']['shipping_preference'] )->toBe( 'NO_SHIPPING' );

    $noAddress = paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) );

    expect( $noAddress['purchase_units'][0] )->not->toHaveKey( 'shipping' )
        ->and( $noAddress['payment_source']['paypal']['experience_context']['shipping_preference'] )->toBe( 'GET_FROM_FILE' );
} );

it( 'builds the PayPal experience context', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.brand_name' => null, 'artisanpack.ecommerce.notifications.store_name' => 'Acme Store' ] );

    $context = paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ], [ 'locale' => 'fr_CA' ] ), context: [
        'return_url' => 'https://shop.test/return',
        'cancel_url' => 'https://shop.test/cancel',
    ] )['payment_source']['paypal']['experience_context'];

    expect( $context )->toMatchArray( [
        'brand_name'  => 'Acme Store',
        'locale'      => 'fr-CA',
        'user_action' => 'PAY_NOW',
        'return_url'  => 'https://shop.test/return',
        'cancel_url'  => 'https://shop.test/cancel',
    ] );

    config( [ 'artisanpack.ecommerce-paypal.brand_name' => 'Acme' ] );

    expect( paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) )['payment_source']['paypal']['experience_context']['brand_name'] )->toBe( 'Acme' );
} );

it( 'resolves the locale to one PayPal supports', function ( string $locale, string $expected ): void {
    expect( paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ), context: [ 'locale' => $locale ] )['payment_source']['paypal']['experience_context']['locale'] )->toBe( $expected );
} )->with( [
    [ 'en', 'en-US' ],
    [ 'es', 'es-ES' ],
    [ 'de', 'de-DE' ],
    [ 'fr', 'fr-FR' ],
    [ 'es_MX', 'es-MX' ],
    [ 'de-CH', 'de-CH' ],
    [ 'nb', 'no-NO' ],
    [ 'xx', 'en-US' ],
    [ 'en_ZZ', 'en-US' ],
] );

it( 'falls back to the storefront checkout routes', function (): void {
    Route::get( 'shop/checkout/return', fn () => '' )->name( OrderPayloadBuilder::STOREFRONT_RETURN_ROUTE );
    Route::get( 'shop/checkout', fn () => '' )->name( OrderPayloadBuilder::STOREFRONT_CANCEL_ROUTE );
    app( 'router' )->getRoutes()->refreshNameLookups();

    $context = paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) )['payment_source']['paypal']['experience_context'];

    expect( $context['return_url'] )->toEndWith( '/shop/checkout/return' )
        ->and( $context['cancel_url'] )->toEndWith( '/shop/checkout' );
} );

it( 'shapes the payment source per gateway', function (): void {
    $cart = CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] );

    expect( paypalPayload( $cart, PayPalCardGateway::class, [ 'return_url' => 'https://shop.test/r' ] )['payment_source'] )
        ->toBe( [ 'card' => [ 'experience_context' => [ 'return_url' => 'https://shop.test/r', 'cancel_url' => 'https://shop.test/r' ] ] ] )
        ->and( paypalPayload( $cart, PayPalCardGateway::class ) )->not->toHaveKey( 'payment_source' )
        ->and( paypalPayload( $cart, PayPalApplePayGateway::class ) )->not->toHaveKey( 'payment_source' )
        ->and( paypalPayload( $cart, context: [ 'payment_source' => 'venmo' ] )['payment_source'] )->toHaveKey( 'venmo' );
} );

it( 'uses the configured intent', function ( string $intent, string $expected ): void {
    config( [ 'artisanpack.ecommerce-paypal.intent' => $intent ] );

    expect( paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) )['intent'] )->toBe( $expected );
} )->with( [ [ 'capture', 'CAPTURE' ], [ 'authorize', 'AUTHORIZE' ], [ 'AUTHORIZE', 'AUTHORIZE' ] ] );

it( 'passes the payload through the orderPayload filter', function (): void {
    addFilter( 'ap.ecommerce.paypal.orderPayload', function ( array $payload, Cart $cart, PayPalGateway $gateway ): array {
        $payload['purchase_units'][0]['description'] = 'Order for cart ' . $cart->getKey() . ' via ' . $gateway->key();

        return $payload;
    }, 10, 3 );

    expect( paypalPayload( CartBuilder::make( [ [ 'Shirt', 1000, 1 ] ] ) )['purchase_units'][0]['description'] )->toBe( 'Order for cart 42 via paypal' );
} );
