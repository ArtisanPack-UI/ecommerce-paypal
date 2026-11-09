<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\EcommercePayPal\Livewire\PayLaterMessage;
use ArtisanPackUI\EcommercePayPal\Support\PayLaterMessaging;
use ArtisanPackUI\EcommerceStorefrontLivewire\Support\StorefrontCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Money\Money;
use Tests\Support\PayPalWebhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    PayPalWebhooks::boot( [
        'methods.paypal.enabled'   => true,
        'methods.paypal.pay_later' => true,
        'pay_later_messaging'      => [ 'checkout' => true, 'cart' => true, 'product' => true ],
    ] );
} );

/**
 * Renders the Blade component.
 *
 * @param  string  $attributes  Component attributes.
 * @param  array<string, mixed>  $data  View data.
 *
 * @return string
 */
function renderPayLaterMessage( string $attributes, array $data = [] ): string
{
    return Blade::render( '<x-ecommerce-paypal::pay-later-message ' . $attributes . ' />', $data );
}

describe( 'blade component', function (): void {
    it( 'renders the message box with a reserved height and the mount script', function (): void {
        $html = renderPayLaterMessage( ':amount="$amount" placement="product"', [ 'amount' => Money::USD( 24000 ) ] );

        expect( $html )->toContain( 'data-paypal-pay-later-message' )
            ->toContain( 'data-placement="product"' )
            ->toContain( 'min-h-6' )
            ->toContain( 'paypal.mountMessages' )
            ->toContain( '240.00' )
            ->toContain( 'vendor\/ecommerce-paypal\/paypal-checkout.js' )
            ->not->toContain( 'client-secret' );
    } );

    it( 'takes minor units in the store currency', function (): void {
        expect( renderPayLaterMessage( ':amount="6000" placement="cart"' ) )->toContain( '60.00' )
            ->toContain( 'USD' );
    } );

    it( 'maps the payment placement to the checkout setting', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.pay_later_messaging.checkout' => false ] );

        expect( renderPayLaterMessage( ':amount="6000" placement="payment"' ) )->toBe( '' );
    } );

    it( 'renders nothing when a gate is closed', function ( array $config, string $attributes ): void {
        config( $config );

        expect( trim( renderPayLaterMessage( $attributes ) ) )->toBe( '' );
    } )->with( [
        'placement off'      => [ [ 'artisanpack.ecommerce-paypal.pay_later_messaging.product' => false ], ':amount="6000" placement="product"' ],
        'pay later off'      => [ [ 'artisanpack.ecommerce-paypal.methods.paypal.pay_later' => false ], ':amount="6000" placement="product"' ],
        'paypal off'         => [ [ 'artisanpack.ecommerce-paypal.methods.paypal.enabled' => false ], ':amount="6000" placement="product"' ],
        'package disabled'   => [ [ 'artisanpack.ecommerce-paypal.enabled' => false ], ':amount="6000" placement="product"' ],
        'no credentials'     => [ [ 'artisanpack.ecommerce-paypal.client_secret' => null ], ':amount="6000" placement="product"' ],
        'zero amount'        => [ [], ':amount="0" placement="product"' ],
        'unknown placement'  => [ [], ':amount="6000" placement="footer"' ],
        'unsupported money'  => [ [], ':amount="6000" currency="JPY" placement="product"' ],
        'not an amount'      => [ [], 'amount="sixty" placement="product"' ],
    ] );
} );

describe( 'livewire component', function (): void {
    it( 'shows a fixed amount', function (): void {
        $component = Livewire::test( PayLaterMessage::class, [ 'placement' => 'product', 'amount' => 24000, 'currency' => 'GBP' ] )
            ->assertSet( 'value', '240.00' )
            ->assertSeeHtml( 'data-paypal-pay-later-message' )
            ->assertSeeHtml( 'wire:ignore' );

        expect( implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) ) )->toContain( "\$wire.\$watch( 'value'" )
            ->and( $component->viewData( 'settings' )['config']['options']['currency'] )->toBe( 'GBP' );
    } );

    it( 'follows the cart total when the cart changes', function (): void {
        $cart = Cart::factory()->create( [ 'currency' => 'USD', 'total_amount' => 6000 ] );
        app( StorefrontCart::class )->remember( $cart );

        $component = Livewire::test( PayLaterMessage::class, [ 'placement' => 'cart' ] )->assertSet( 'value', '60.00' );

        $cart->forceFill( [ 'total_amount' => 12000 ] )->save();

        $component->dispatch( 'ecommerce-cart-updated' )->assertSet( 'value', '120.00' );

        $cart->forceFill( [ 'total_amount' => 0 ] )->save();

        $component->dispatch( 'ecommerce-cart-updated' )->assertSet( 'value', '' )
            ->assertSeeHtml( 'data-paypal-pay-later-message' )
            ->assertSeeHtml( 'hidden' );
    } );

    it( 'keeps the box for an empty cart, hidden, so the message can come back', function (): void {
        $cart = Cart::factory()->create( [ 'currency' => 'USD', 'total_amount' => 0 ] );
        app( StorefrontCart::class )->remember( $cart );

        $component = Livewire::test( PayLaterMessage::class, [ 'placement' => 'cart' ] )
            ->assertSet( 'value', '' )
            ->assertSeeHtml( 'data-paypal-pay-later-message' );

        expect( implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) ) )->toContain( 'target.hidden = ! amount' );
    } );

    it( 'renders an empty root without a cart or when gated', function (): void {
        Livewire::test( PayLaterMessage::class, [ 'placement' => 'cart' ] )
            ->assertSet( 'value', '' )
            ->assertSeeHtml( 'data-paypal-pay-later-live' )
            ->assertDontSeeHtml( 'data-paypal-pay-later-message' );
    } );

    it( 'is registered under its spec name', function (): void {
        expect( Blade::render( '<livewire:artisanpack-ecommerce-paypal-pay-later-message placement="product" :amount="6000" />' ) )
            ->toContain( 'data-paypal-pay-later-message' );
    } );
} );

it( 'normalizes amounts', function (): void {
    expect( PayLaterMessaging::money( Money::EUR( 5 ) )?->getCurrency()->getCode() )->toBe( 'EUR' )
        ->and( PayLaterMessaging::money( '6000', 'gbp' )?->getAmount() )->toBe( '6000' )
        ->and( PayLaterMessaging::money( 1.5 ) )->toBeNull()
        ->and( PayLaterMessaging::money( 100, 'NOPE!' ) )->toBeNull();
} );
