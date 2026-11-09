<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalButtons;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommerceStorefrontLivewire\Registries\PaymentDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot();
} );

/**
 * The driver's props, as the checkout mounts it.
 *
 * @param  array<string, mixed>  $options  Option overrides.
 * @param  array<string, mixed>  $config   Config overrides.
 *
 * @return array<string, mixed>
 */
function paypalButtonsProps( array $options = [], array $config = [] ): array
{
    return [
        'config'       => $config + [
            'driver'          => 'paypal-buttons',
            'flow'            => 'embedded',
            'publishable_key' => 'client-id',
            'client_secret'   => Orders::ORDER_ID,
            'redirect_url'    => null,
            'options'         => $options + [ 'order_id' => Orders::ORDER_ID, 'currency' => 'USD', 'components' => [ 'buttons' ], 'restart' => false ],
            'gateway'         => 'paypal',
        ],
        'gateway'      => 'paypal',
        'gatewayLabel' => 'PayPal',
        'reference'    => Orders::ORDER_ID,
        'token'        => 'action-token',
    ];
}

describe( 'registration', function (): void {
    it( 'registers the paypal-buttons driver with the storefront', function (): void {
        expect( app( PaymentDriverRegistry::class )->component( 'paypal-buttons' ) )->toBe( PayPalButtons::COMPONENT );
    } );

    it( 'leaves a driver the host configured in place', function (): void {
        $registry = app( PaymentDriverRegistry::class );
        $registry->register( 'paypal-buttons', 'host-paypal-buttons' );

        PayPalWebhooks::boot();

        expect( $registry->component( 'paypal-buttons' ) )->toBe( 'host-paypal-buttons' );
    } );

    it( 'is the driver the paypal gateway names', function (): void {
        expect( app( PaymentGatewayRegistry::class )->get( 'paypal' )->driver() )->toBe( PayPalButtons::DRIVER );
    } );
} );

describe( 'rendering', function (): void {
    it( 'renders the buttons inside wire:ignore with live regions and the shared module', function (): void {
        $component = Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->assertSeeHtml( 'data-payment-driver="paypal-buttons"' )
            ->assertSeeHtml( 'wire:ignore' )
            ->assertSeeHtml( 'data-paypal-buttons' )
            ->assertSeeHtml( 'role="alert"' )
            ->assertSeeHtml( 'aria-live="assertive"' )
            ->assertSeeHtml( 'role="status"' )
            ->assertSeeHtml( 'aria-label="Pay with PayPal"' )
            ->assertDontSeeHtml( 'data-paypal-restart' )
            ->assertDontSeeHtml( 'data-payment-unavailable' );

        $script = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

        expect( $script )->toContain( 'paypal.mountButtons' )
            ->toContain( '$wire.confirm( data?.orderID' )
            ->toContain( 'focusHeading()' )
            ->and( $component->viewData( 'paypal' )['module'] )->toBe( asset( 'vendor/ecommerce-paypal/paypal-checkout.js' ) . '?v=' . EcommercePayPalServiceProvider::VERSION )
            ->and( $component->viewData( 'paypal' )['config'] )->toMatchArray( [ 'publishable_key' => 'client-id', 'client_secret' => Orders::ORDER_ID ] );
    } );

    it( 'tells the buyer to pick another funding source after a decline', function (): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps( [ 'restart' => true ] ) )
            ->assertSeeHtml( 'data-paypal-restart' )
            ->assertSee( 'PayPal declined that payment method. Choose another one in PayPal to finish your order.' );
    } );

    it( 'says the method isn\'t available without a client id or Order', function ( array $config ): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps( [], $config ) )
            ->assertSeeHtml( 'data-payment-unavailable' )
            ->assertDontSeeHtml( 'data-paypal-buttons' );
    } )->with( [
        'no client id' => [ [ 'publishable_key' => '' ] ],
        'no order'     => [ [ 'client_secret' => null ] ],
    ] );

    it( 'translates its strings', function (): void {
        app()->setLocale( 'fr' );

        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->assertSee( __( 'Loading PayPal…' ) )
            ->assertDontSee( 'Loading PayPal…' );
    } );

    it( 'keeps its props locked', function (): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps() )->set( 'reference', 'OTHER' );
    } )->throws( CannotUpdateLockedPropertyException::class );
} );

describe( 'confirm', function (): void {
    it( 'reports an Order PayPal says is approved', function (): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-confirmed', reference: Orders::ORDER_ID, gateway: 'paypal', token: 'action-token' )
            ->assertNotDispatched( 'payment-failed' );

        $this->paypal->assertSent( 'orders.get' );
    } );

    it( 'refuses an Order that isn\'t the session\'s', function ( ?string $orderId ): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->call( 'confirm', $orderId )
            ->assertDispatched( 'payment-failed' )
            ->assertNotDispatched( 'payment-confirmed' );

        $this->paypal->assertNotSent( 'orders.get' );
    } )->with( [ 'another order' => 'OTHER-ORDER', 'none' => null ] );

    it( 'fails when PayPal hasn\'t approved the Order', function ( string $status, string $message ): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'status' => $status ] ) );

        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-failed', message: $message )
            ->assertNotDispatched( 'payment-confirmed' );
    } )->with( [
        'created'       => [ 'CREATED', 'Your PayPal payment wasn\'t approved. Try again or use another payment method.' ],
        'payer action'  => [ 'PAYER_ACTION_REQUIRED', 'PayPal needs you to finish another step. Try again.' ],
    ] );

    it( 'fails when PayPal can\'t be reached', function (): void {
        $this->paypal->error( 'orders.get', 'internal-server-error' );

        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-failed', message: 'We couldn\'t check your payment with PayPal. Try again.' );
    } );
} );

describe( 'fail', function (): void {
    it( 'reports an SDK error as a failed payment', function (): void {
        Livewire::test( PayPalButtons::class, paypalButtonsProps() )
            ->call( 'fail', '' )
            ->assertDispatched( 'payment-failed', gateway: 'paypal' )
            ->assertNotDispatched( 'payment-confirmed' );
    } );
} );
