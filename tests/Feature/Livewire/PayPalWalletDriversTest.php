<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalApplePay;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalGooglePay;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommerceStorefrontLivewire\Registries\PaymentDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
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
 * @param  string                $gateway  Gateway key.
 * @param  string                $driver   Driver key.
 * @param  array<string, mixed>  $config   Config overrides.
 *
 * @return array<string, mixed>
 */
function walletProps( string $gateway, string $driver, array $config = [] ): array
{
    return [
        'config'       => $config + [
            'driver'          => $driver,
            'flow'            => 'embedded',
            'publishable_key' => 'client-id',
            'client_secret'   => Orders::ORDER_ID,
            'options'         => [ 'order_id' => Orders::ORDER_ID, 'currency' => 'USD', 'amount' => '100.00', 'mode' => 'sandbox' ],
        ],
        'gateway'      => $gateway,
        'gatewayLabel' => 'Wallet',
        'reference'    => Orders::ORDER_ID,
        'token'        => 'action-token',
    ];
}

/**
 * An approved Order paid with `$source` whose 3-D Secure result is `$shift`.
 *
 * @param  string       $source  `apple_pay` or `google_pay`.
 * @param  string|null  $shift   Liability shift.
 *
 * @return array<string, mixed>
 */
function walletOrder( string $source, ?string $shift ): array
{
    $card = [ 'brand' => 'VISA', 'last_digits' => '1111' ];

    if ( null !== $shift ) {
        $card['authentication_result'] = [ 'liability_shift' => $shift ];
    }

    return Orders::body( 'orders/approved', [ 'payment_source' => [ $source => [ 'card' => $card ] ], 'payer' => null ] );
}

dataset( 'wallets', [
    'apple pay'  => [ PayPalApplePay::class, 'paypal-apple-pay', 'apple_pay', 'mountApplePay', 'Apple Pay' ],
    'google pay' => [ PayPalGooglePay::class, 'paypal-google-pay', 'google_pay', 'mountGooglePay', 'Google Pay' ],
] );

it( 'registers the driver the gateway names', function ( string $class, string $key ): void {
    expect( app( PaymentDriverRegistry::class )->component( $class::DRIVER ) )->toBe( $class::COMPONENT )
        ->and( app( PaymentGatewayRegistry::class )->get( $key )->driver() )->toBe( $class::DRIVER );
} )->with( 'wallets' );

it( 'renders the wallet button slot and mounts it through the shared module', function ( string $class, string $key, string $source, string $mount, string $label ): void {
    config( [ 'artisanpack.ecommerce-paypal.brand_name' => 'Ada\'s Shop' ] );

    $component = Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->assertSeeHtml( 'data-payment-driver="' . $class::DRIVER . '"' )
        ->assertSeeHtml( 'wire:ignore' )
        ->assertSeeHtml( 'data-paypal-wallet' )
        ->assertSeeHtml( 'aria-label="Pay with ' . $label . '"' )
        ->assertSeeHtml( 'role="status"' )
        ->assertDontSeeHtml( 'data-payment-unavailable' );

    $script = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

    expect( $component->viewData( 'paypal' )['mount'] )->toBe( $mount )
        ->and( $component->viewData( 'paypal' )['label'] )->toBe( 'Ada\'s Shop' )
        ->and( $component->viewData( 'paypal' )['messages']['ineligible'] )->toBe( $label . ' isn\'t available on this device. Choose another payment method.' )
        ->and( $script )->toContain( 'paypal[ settings.mount ]' )
        ->toContain( 'container.hidden = true' )
        ->toContain( '$wire.fail( settings.messages.ineligible )' )
        ->toContain( '$wire.confirm( data?.orderID' );
} )->with( 'wallets' );

it( 'labels the sheet total with the app name without a brand name', function ( string $class, string $key ): void {
    config( [ 'artisanpack.ecommerce-paypal.brand_name' => null, 'app.name' => 'Shop' ] );

    expect( Livewire::test( $class, walletProps( $key, $class::DRIVER ) )->viewData( 'paypal' )['label'] )->toBe( 'Shop' );
} )->with( 'wallets' );

it( 'says the method isn\'t available without a client id or Order', function ( string $class, string $key ): void {
    Livewire::test( $class, walletProps( $key, $class::DRIVER, [ 'client_secret' => '' ] ) )
        ->assertSeeHtml( 'data-payment-unavailable' )
        ->assertDontSeeHtml( 'data-paypal-wallet' );
} )->with( 'wallets' );

it( 'confirms an approved wallet payment', function ( string $class, string $key, string $source ): void {
    $this->paypal->respond( 'orders.get', walletOrder( $source, 'POSSIBLE' ) );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'confirm', Orders::ORDER_ID )
        ->assertDispatched( 'payment-confirmed', reference: Orders::ORDER_ID, gateway: $key )
        ->assertNotDispatched( 'payment-failed' );
} )->with( 'wallets' );

it( 'refuses a wallet payment whose 3-D Secure result doesn\'t shift liability', function ( string $class, string $key, string $source ): void {
    $this->paypal->respond( 'orders.get', walletOrder( $source, 'NO' ) );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'confirm', Orders::ORDER_ID )
        ->assertDispatched( 'payment-failed', message: 'Your bank couldn\'t verify this card. Try another card or payment method.' )
        ->assertNotDispatched( 'payment-confirmed' );
} )->with( 'wallets' );

it( 'fails a wallet payment PayPal hasn\'t approved or can\'t confirm', function ( string $class, string $key, string $source, string $mount, string $label ): void {
    $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'status' => 'CREATED' ] ) );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'confirm', Orders::ORDER_ID )
        ->assertDispatched( 'payment-failed', message: 'Your ' . $label . ' payment wasn\'t approved. Try again or use another payment method.' );

    $this->paypal->error( 'orders.get', 'internal-server-error' );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'confirm', Orders::ORDER_ID )
        ->assertDispatched( 'payment-failed', message: 'We couldn\'t check your ' . $label . ' payment with PayPal. Try again.' );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'confirm', 'OTHER' )
        ->assertDispatched( 'payment-failed', message: 'Your ' . $label . ' payment couldn\'t be confirmed. Try again.' );
} )->with( 'wallets' );

it( 'reports an ineligible device as a failed payment', function ( string $class, string $key ): void {
    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )
        ->call( 'fail', 'Not available.' )
        ->assertDispatched( 'payment-failed', message: 'Not available.', gateway: $key )
        ->assertNotDispatched( 'payment-confirmed' );
} )->with( 'wallets' );

it( 'never offers saving a wallet', function ( string $class, string $key ): void {
    config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => true ] );

    Livewire::test( $class, walletProps( $key, $class::DRIVER ) )->assertDontSeeHtml( 'data-paypal-save-method' );
} )->with( 'wallets' );
