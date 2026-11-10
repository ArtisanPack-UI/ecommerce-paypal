<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalCardFields;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommerceStorefrontLivewire\Registries\PaymentDriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\CartBuilder;
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
function cardFieldsProps( array $options = [], array $config = [] ): array
{
    return [
        'config'       => $config + [
            'driver'          => 'paypal-card-fields',
            'flow'            => 'embedded',
            'publishable_key' => 'client-id',
            'client_secret'   => Orders::ORDER_ID,
            'redirect_url'    => null,
            'options'         => $options + [ 'order_id' => Orders::ORDER_ID, 'currency' => 'USD', 'components' => [ 'card-fields' ], 'three_d_secure' => 'SCA_WHEN_REQUIRED', 'require_liability_shift' => true ],
        ],
        'gateway'      => 'paypal-card',
        'gatewayLabel' => 'Credit or debit card',
        'reference'    => Orders::ORDER_ID,
        'token'        => 'action-token',
    ];
}

describe( 'registration', function (): void {
    it( 'registers the paypal-card-fields driver the card gateway names', function (): void {
        expect( app( PaymentDriverRegistry::class )->component( 'paypal-card-fields' ) )->toBe( PayPalCardFields::COMPONENT )
            ->and( app( PaymentGatewayRegistry::class )->get( 'paypal-card' )->driver() )->toBe( PayPalCardFields::DRIVER );
    } );
} );

describe( 'rendering', function (): void {
    it( 'renders labeled PayPal-hosted fields, inline errors, live regions, and a disabled submit', function (): void {
        $component = Livewire::test( PayPalCardFields::class, cardFieldsProps() )
            ->assertSeeHtml( 'data-payment-driver="paypal-card-fields"' )
            ->assertSeeHtml( 'wire:ignore' )
            ->assertSeeHtml( 'data-paypal-card-field="number"' )
            ->assertSeeHtml( 'data-paypal-card-field="expiry"' )
            ->assertSeeHtml( 'data-paypal-card-field="cvv"' )
            ->assertSeeHtml( 'data-paypal-card-field="name"' )
            ->assertSeeHtml( 'data-paypal-card-error="number"' )
            ->assertSeeHtml( 'aria-live="assertive"' )
            ->assertSeeHtml( 'aria-live="polite"' )
            ->assertSee( [ 'Card number', 'Expiration date', 'Security code', 'Name on card', 'Pay now' ] )
            ->assertSeeHtml( 'data-paypal-card-submit' )
            ->assertDontSeeHtml( 'data-paypal-save-method' )
            ->assertDontSeeHtml( 'data-payment-unavailable' );

        expect( $component->html() )->toMatch( '/<button[^>]*disabled[^>]*data-paypal-card-submit|<button[^>]*data-paypal-card-submit[^>]*disabled/' );

        $script = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

        expect( $script )->toContain( 'paypal.mountCardFields' )
            ->toContain( 'card.submit()' )
            ->toContain( '$wire.confirm( data?.orderID' )
            ->toContain( 'setProcessing( true )' )
            ->toContain( 'onBlur' );
    } );

    it( 'announces each field\'s error politely and names the invalid fields in words', function (): void {
        $component = Livewire::test( PayPalCardFields::class, cardFieldsProps() );
        $html      = $component->html();
        $settings  = $component->viewData( 'paypal' );
        $script    = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

        preg_match_all( '/<p[^>]*data-paypal-card-error="[^"]+"[^>]*>/', $html, $errors );

        expect( $errors[0] )->toHaveCount( 4 );

        foreach ( $errors[0] as $error ) {
            expect( $error )->toContain( 'aria-live="polite"' );
        }

        expect( $settings['messages']['invalid'] )->toBe( 'Check these card details: :fields.' )
            ->and( $settings['labels'] )->toBe( [ 'name' => 'Name on card', 'number' => 'Card number', 'expiry' => 'Expiration date', 'cvv' => 'Security code' ] )
            ->and( $settings['heading'] )->toBe( [ 'ec-checkout-placement-heading', 'ec-checkout-step-payment' ] )
            ->and( $script )->toContain( 'Intl.ListFormat' )
            ->toContain( 'onError: () => {' )
            ->toContain( '$wire.fail( settings.messages.failed )' )
            ->toContain( 'announce( statusBox, settings.messages.ineligible )' )
            ->not->toContain( "\$wire.fail( '' )" );
    } );

    it( 'translates the invalid-fields summary', function ( string $locale ): void {
        app()->setLocale( $locale );

        $settings = Livewire::test( PayPalCardFields::class, cardFieldsProps() )->viewData( 'paypal' );

        expect( $settings['messages']['invalid'] )->not->toBe( 'Check these card details: :fields.' )
            ->toContain( ':fields' );
    } )->with( [ 'es', 'fr', 'de' ] );

    it( 'never takes card data into Livewire', function (): void {
        $component = Livewire::test( PayPalCardFields::class, cardFieldsProps() );
        $html      = $component->html();

        expect( array_keys( $component->instance()->all() ) )->toEqualCanonicalizing( [ 'config', 'gateway', 'gatewayLabel', 'reference', 'token', 'saveMethod', 'savedCard', 'payingWithSaved' ] )
            ->and( $html )->not->toContain( 'wire:model="number' )
            ->and( preg_match( '/<input[^>]*(number|cvv|cvc|expiry|card)/i', $html ) )->toBe( 0 );

        $script = implode( "\n", (array) ( $component->effects['scripts'] ?? [] ) );

        // The only server calls the script makes carry the Order id.
        preg_match_all( '/\$wire\.(\w+)\(([^)]*)\)/', $script, $calls, PREG_SET_ORDER );

        foreach ( $calls as [ , $method, $arguments ] ) {
            expect( $method )->toBeIn( [ 'confirm', 'fail', 'on' ] )
                ->and( $arguments )->not->toContain( 'state' );
        }
    } );

    it( 'says the method isn\'t available without a client id or Order', function (): void {
        Livewire::test( PayPalCardFields::class, cardFieldsProps( [], [ 'publishable_key' => '' ] ) )
            ->assertSeeHtml( 'data-payment-unavailable' )
            ->assertDontSeeHtml( 'data-paypal-card-field' );
    } );

    it( 'keeps its props locked', function (): void {
        Livewire::test( PayPalCardFields::class, cardFieldsProps() )->set( 'config', [] );
    } )->throws( CannotUpdateLockedPropertyException::class );
} );

describe( 'confirm', function (): void {
    it( 'reports an approved card whose 3-D Secure result shifts liability', function ( ?string $shift ): void {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( $shift ) );

        Livewire::test( PayPalCardFields::class, cardFieldsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-confirmed', reference: Orders::ORDER_ID, gateway: 'paypal-card' )
            ->assertNotDispatched( 'payment-failed' );
    } )->with( [ 'possible' => 'POSSIBLE', 'yes' => 'YES', 'no 3-D Secure result' => null ] );

    it( 'refuses a liability shift of NO or UNKNOWN without capturing', function ( string $shift ): void {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( $shift ) );

        Livewire::test( PayPalCardFields::class, cardFieldsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-failed', message: 'Your bank couldn\'t verify this card. Try another card or payment method.' )
            ->assertNotDispatched( 'payment-confirmed' );

        $this->paypal->assertNotSent( 'orders.capture' );
    } )->with( [ 'NO', 'UNKNOWN' ] );

    it( 'accepts any liability shift when the store doesn\'t require one', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.require_liability_shift' => false ] );
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'NO' ) );

        Livewire::test( PayPalCardFields::class, cardFieldsProps() )
            ->call( 'confirm', Orders::ORDER_ID )
            ->assertDispatched( 'payment-confirmed' );
    } );

    it( 'fails an Order that isn\'t the session\'s, or that PayPal hasn\'t approved', function ( ?string $orderId, ?string $status, string $message ): void {
        if ( null !== $status ) {
            $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'status' => $status ] ) );
        }

        Livewire::test( PayPalCardFields::class, cardFieldsProps() )
            ->call( 'confirm', $orderId )
            ->assertDispatched( 'payment-failed', message: $message )
            ->assertNotDispatched( 'payment-confirmed' );
    } )->with( [
        'another order' => [ 'OTHER', null, 'Your card payment couldn\'t be confirmed. Try again.' ],
        'needs 3DS'     => [ Orders::ORDER_ID, 'PAYER_ACTION_REQUIRED', 'Your bank needs you to finish verifying this card. Try again.' ],
        'not approved'  => [ Orders::ORDER_ID, 'CREATED', 'Your card wasn\'t approved. Try again or use another payment method.' ],
    ] );
} );

describe( '3-D Secure setting', function (): void {
    it( 'sends the configured verification method on card Orders', function ( string $configured, string $sent ): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.three_d_secure' => $configured ] );

        $payload = app( OrderPayloadBuilder::class )->build( CartBuilder::make( [ [ 'Mug', 1000, 1 ] ] ), app( PaymentGatewayRegistry::class )->get( 'paypal-card' ) );

        expect( $payload['payment_source']['card']['attributes']['verification'] )->toBe( [ 'method' => $sent ] );
    } )->with( [
        'when required' => [ 'SCA_WHEN_REQUIRED', 'SCA_WHEN_REQUIRED' ],
        'always'        => [ 'sca_always', 'SCA_ALWAYS' ],
        'unknown value' => [ 'SOMETIMES', 'SCA_WHEN_REQUIRED' ],
    ] );

    it( 'leaves PayPal-wallet Orders without verification', function (): void {
        $payload = app( OrderPayloadBuilder::class )->build( CartBuilder::make( [ [ 'Mug', 1000, 1 ] ] ), app( PaymentGatewayRegistry::class )->get( 'paypal' ) );

        expect( $payload['payment_source']['paypal'] )->not->toHaveKey( 'attributes' );
    } );
} );
