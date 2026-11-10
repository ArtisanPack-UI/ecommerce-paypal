<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\AccountMenuRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Livewire\Account\SavedPaymentMethods;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );
} );

describe( 'account menu', function (): void {
    it( 'adds "Saved payment methods" to the account menu, tied to the satellite', function (): void {
        $customer = Customer::factory()->create();
        $entry    = collect( app( AccountMenuRegistry::class )->visibleTo( $customer ) )->firstWhere( 'key', EcommercePayPalServiceProvider::ACCOUNT_MENU_KEY );

        expect( $entry )->toMatchArray( [
            'label' => 'Saved payment methods',
            'route' => EcommercePayPalServiceProvider::ACCOUNT_ROUTE,
            'url'   => route( EcommercePayPalServiceProvider::ACCOUNT_ROUTE ),
        ] );
    } );

    it( 'hides the entry while vaulting is off', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

        expect( collect( app( AccountMenuRegistry::class )->visibleTo( Customer::factory()->create() ) )->pluck( 'key' ) )
            ->not->toContain( EcommercePayPalServiceProvider::ACCOUNT_MENU_KEY );
    } );

    it( 'hides the entry once the satellite is uninstalled', function (): void {
        $satellites = Mockery::mock( SatelliteRegistry::class )->makePartial();
        $satellites->shouldReceive( 'isActive' )->with( EcommercePayPalServiceProvider::PACKAGE_NAME )->andReturnFalse();
        app()->instance( SatelliteRegistry::class, $satellites );

        expect( collect( app( AccountMenuRegistry::class )->visibleTo( Customer::factory()->create() ) )->pluck( 'key' ) )
            ->not->toContain( EcommercePayPalServiceProvider::ACCOUNT_MENU_KEY );
    } );
} );

describe( 'page', function (): void {
    it( 'serves the page in the account shell, kept private', function (): void {
        Shoppers::signIn();

        $this->get( route( EcommercePayPalServiceProvider::ACCOUNT_ROUTE ) )
            ->assertOk()
            ->assertSee( 'Saved payment methods' )
            ->assertSeeLivewire( SavedPaymentMethods::COMPONENT )
            ->assertHeader( 'X-Robots-Tag', 'noindex, nofollow' )
            ->assertHeader( 'Referrer-Policy', 'same-origin' );
    } );

    it( 'requires a signed-in shopper', function (): void {
        $this->getJson( route( EcommercePayPalServiceProvider::ACCOUNT_ROUTE ) )->assertUnauthorized();
    } );
} );

describe( 'component', function (): void {
    it( 'lists the shopper\'s methods for this mode, default first, and nobody else\'s', function (): void {
        $customer = Shoppers::signIn();

        PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'display' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'expiry' => '2020-01' ] ] );
        PayPalPaymentToken::factory()->paypal()->default()->create( [ 'customer_id' => $customer->id, 'display' => [ 'email' => 'ada@example.test' ] ] );
        PayPalPaymentToken::factory()->live()->create( [ 'customer_id' => $customer->id, 'display' => [ 'brand' => 'AMEX', 'last_digits' => '0005' ] ] );
        PayPalPaymentToken::factory()->create( [ 'display' => [ 'brand' => 'DISCOVER', 'last_digits' => '9999' ] ] );

        Livewire::test( SavedPaymentMethods::class )
            ->assertSeeInOrder( [ 'PayPal (ada@example.test)', 'Default', 'Mastercard ending in 4444', 'Expired', 'Expired 01/2020' ] )
            ->assertDontSee( [ 'Amex ending in 0005', 'Discover ending in 9999' ] )
            ->assertDontSeeHtml( 'data-paypal-no-saved-methods' );
    } );

    it( 'says so when nothing is saved', function (): void {
        Shoppers::signIn();

        Livewire::test( SavedPaymentMethods::class )
            ->assertSeeHtml( 'data-paypal-no-saved-methods' )
            ->assertSee( 'No saved payment methods' );
    } );

    it( 'makes a method the default, clearing the old one', function (): void {
        $customer = Shoppers::signIn();
        $old      = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id ] );
        $new      = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        Livewire::test( SavedPaymentMethods::class )->call( 'makeDefault', $new->id )->assertHasNoErrors();

        expect( $new->refresh()->is_default )->toBeTrue()
            ->and( $old->refresh()->is_default )->toBeFalse();
    } );

    it( 'deletes at PayPal first, then here, after confirming', function (): void {
        $customer = Shoppers::signIn();
        $card     = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-del' ] );
        $other    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        Livewire::test( SavedPaymentMethods::class )
            ->call( 'confirmDelete', $card->id )
            ->assertSet( 'confirmingDelete', true )
            ->assertSet( 'deletingId', $card->id )
            ->call( 'delete' )
            ->assertSet( 'confirmingDelete', false )
            ->assertSet( 'deletions', 1 );

        $this->paypal->assertSent( 'vault.payment_tokens.delete', fn ( Request $request ): bool => str_ends_with( $request->url(), '/v3/vault/payment-tokens/tok-del' ) );

        expect( $card->refresh()->trashed() )->toBeTrue()
            ->and( $other->refresh()->is_default )->toBeTrue();
    } );

    it( 'forgets the method it was asked about when the shopper cancels', function (): void {
        $customer = Shoppers::signIn();
        $card     = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        Livewire::test( SavedPaymentMethods::class )
            ->call( 'confirmDelete', $card->id )
            ->assertSet( 'deletingId', $card->id )
            ->call( 'cancelDelete' )
            ->assertSet( 'confirmingDelete', false )
            ->assertSet( 'deletingId', null )
            ->assertSeeHtml( 'data-paypal-saved-method-delete-cancel' );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );

        expect( $card->refresh()->trashed() )->toBeFalse();
    } );

    it( 'keeps the method when PayPal can\'t delete it', function (): void {
        Exceptions::fake();

        $customer = Shoppers::signIn();
        $card     = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error', times: 3 );

        Livewire::test( SavedPaymentMethods::class )
            ->call( 'confirmDelete', $card->id )
            ->call( 'delete' )
            ->assertSet( 'deletions', 0 );

        expect( $card->refresh()->trashed() )->toBeFalse();
    } );

    it( 'never touches another customer\'s method', function ( string $action ): void {
        Shoppers::signIn();

        $theirs = PayPalPaymentToken::factory()->create( [ 'customer_id' => Customer::factory()->create()->id ] );

        Livewire::test( SavedPaymentMethods::class )->call( $action, $theirs->id )->assertNotFound();

        expect( $theirs->refresh()->trashed() )->toBeFalse()
            ->and( $theirs->is_default )->toBeFalse();

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
    } )->with( [ 'confirmDelete', 'makeDefault' ] );

    it( 'shows nothing to a signed-in user without a customer record', function (): void {
        $this->actingAs( new GenericUser( [ 'id' => 99 ] ) );

        Livewire::test( SavedPaymentMethods::class )->assertSeeHtml( 'data-paypal-no-saved-methods' );
    } );
} );
