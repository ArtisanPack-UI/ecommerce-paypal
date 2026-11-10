<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\EcommercePayPal\Livewire\PayLaterMessage;
use ArtisanPackUI\EcommercePayPal\Support\PayLaterMessaging;
use ArtisanPackUI\EcommerceStorefrontLivewire\Support\StorefrontCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Vite;
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

    it( 'gives the inline script Vite\'s CSP nonce', function (): void {
        Vite::useCspNonce( 'abc' );

        expect( renderPayLaterMessage( ':amount="6000" placement="product"' ) )->toContain( '<script type="module" nonce="abc"' );
    } );

    it( 'prefers an explicit nonce over Vite\'s', function (): void {
        Vite::useCspNonce( 'abc' );

        expect( renderPayLaterMessage( ':amount="6000" placement="product" nonce="xyz"' ) )->toContain( '<script type="module" nonce="xyz"' )
            ->not->toContain( 'nonce="abc"' );
    } );

    it( 'adds no nonce when the page uses none', function (): void {
        expect( renderPayLaterMessage( ':amount="6000" placement="product"' ) )->toContain( '<script type="module"' )
            ->not->toContain( 'nonce=' );
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

    it( 'mounts the message when the shopper\'s first cart is created after the component rendered', function (): void {
        $component = Livewire::test( PayLaterMessage::class, [ 'placement' => 'cart' ] )
            ->assertSet( 'value', '' )
            ->assertDontSeeHtml( 'data-paypal-settings=' );

        $script = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

        // The script is always registered and finds the box when it appears.
        expect( $script )->toContain( "root.querySelector( '[data-paypal-pay-later-message]' )" )
            ->toContain( 'target.dataset.paypalSettings' );

        $cart = Cart::factory()->create( [ 'currency' => 'USD', 'total_amount' => 6000 ] );
        app( StorefrontCart::class )->remember( $cart );

        $component->dispatch( 'ecommerce-cart-updated' )
            ->assertSet( 'value', '60.00' )
            ->assertSeeHtml( 'data-paypal-pay-later-message' )
            ->assertSeeHtml( 'data-paypal-settings=' )
            ->assertDontSeeHtml( ' hidden' );
    } );

    it( 'loads without storefront-livewire', function (): void {
        // The Pay Later components may not reference the storefront's driver
        // classes: those extend a class only storefront-livewire provides.
        foreach ( [ 'src/Livewire/PayLaterMessage.php', 'src/View/Components/PayLaterMessage.php', 'src/Support/PayLaterMessaging.php', 'src/Support/PayPalCheckoutModule.php' ] as $file ) {
            $source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/' . $file );

            expect( $source )->not->toContain( 'PayPalButtons' )
                ->not->toContain( 'Livewire\\Payment\\' );
        }

        expect( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Livewire/PayLaterMessage.php' ) )->toContain( 'class_exists( StorefrontCart::class )' );
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
            ->assertDontSeeHtml( 'data-paypal-settings=' );
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
