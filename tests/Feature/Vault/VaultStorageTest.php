<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Jobs\DeletePayPalVaultTokens;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'     => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret' => 'client-secret',
        'artisanpack.ecommerce-paypal.http.retries'  => 0,
    ] );

    Sleep::fake();

    $this->paypal = PayPalFake::fake();
    $this->vault  = app( PayPalVault::class );
} );

describe( 'schema', function (): void {
    it( 'creates both tables from the package migrations directory', function (): void {
        expect( Schema::getColumnListing( 'ecommerce_paypal_customers' ) )
            ->toEqualCanonicalizing( [ 'id', 'customer_id', 'paypal_customer_id', 'mode', 'created_at', 'updated_at' ] )
            ->and( Schema::getColumnListing( 'ecommerce_paypal_payment_tokens' ) )
            ->toEqualCanonicalizing( [ 'id', 'customer_id', 'paypal_token_id', 'gateway_key', 'type', 'display', 'is_default', 'mode', 'last_used_at', 'created_at', 'updated_at', 'deleted_at' ] )
            ->and( glob( EcommercePayPalServiceProvider::migrationsPath() . '/*.php' ) )->toHaveCount( 2 );
    } );

    it( 'passes the engine PCI column lint', function (): void {
        expect( Artisan::call( 'ecommerce:lint:pci-columns', [ '--path' => [ EcommercePayPalServiceProvider::migrationsPath() ] ] ) )->toBe( 0 );
    } );

    it( 'keeps one token id per mode', function (): void {
        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-1' ] );
        PayPalPaymentToken::factory()->live()->create( [ 'paypal_token_id' => 'tok-1' ] );

        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-1' ] );
    } )->throws( Illuminate\Database\UniqueConstraintViolationException::class );
} );

describe( 'models', function (): void {
    it( 'casts and relates tokens to the engine customer and the PayPal customer', function (): void {
        $customer = Customer::factory()->create();
        $paypal   = PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'PAYPAL-CUS' ] );
        $token    = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id, 'last_used_at' => now() ] );

        PayPalPaymentToken::factory()->live()->create( [ 'customer_id' => $customer->id ] );

        expect( $token->is_default )->toBeTrue()
            ->and( $token->display )->toBeArray()->toHaveKeys( [ 'brand', 'last_digits', 'expiry' ] )
            ->and( $token->last_used_at )->toBeInstanceOf( Illuminate\Support\Carbon::class )
            ->and( $token->customer->is( $customer ) )->toBeTrue()
            ->and( $paypal->customer->is( $customer ) )->toBeTrue()
            ->and( $paypal->paymentTokens()->forMode( $paypal->mode )->pluck( 'id' )->all() )->toBe( [ $token->id ] )
            ->and( PayPalCustomer::query()->with( 'paymentTokens' )->sole()->paymentTokens )->toHaveCount( 2 );
    } );

    it( 'scopes by mode', function (): void {
        PayPalPaymentToken::factory()->count( 2 )->create();
        PayPalPaymentToken::factory()->live()->create();
        PayPalCustomer::factory()->live()->create();

        expect( PayPalPaymentToken::query()->forMode( 'sandbox' )->count() )->toBe( 2 )
            ->and( PayPalPaymentToken::query()->forMode( 'live' )->count() )->toBe( 1 )
            ->and( PayPalCustomer::query()->forMode( 'live' )->count() )->toBe( 1 )
            ->and( PayPalCustomer::query()->forMode( 'sandbox' )->count() )->toBe( 0 );
    } );

    it( 'soft deletes tokens', function (): void {
        $token = PayPalPaymentToken::factory()->create();

        $token->delete();

        expect( PayPalPaymentToken::query()->count() )->toBe( 0 )
            ->and( PayPalPaymentToken::withTrashed()->count() )->toBe( 1 );
    } );
} );

describe( 'saving and defaults', function (): void {
    it( 'stores a token, the PayPal customer id, and makes the first one the default', function (): void {
        $customer = Customer::factory()->create();

        $token = $this->vault->save( $customer->id, 'tok-1', 'paypal-card', 'card', [ 'brand' => 'VISA', 'last_digits' => '1111' ], 'PAYPAL-CUS' );

        expect( $token->is_default )->toBeTrue()
            ->and( $token->mode )->toBe( 'sandbox' )
            ->and( $token->last_used_at )->not->toBeNull()
            ->and( $this->vault->paypalCustomerId( $customer->id ) )->toBe( 'PAYPAL-CUS' )
            ->and( $this->vault->save( $customer->id, 'tok-2', 'paypal', 'paypal', [ 'email' => 'a@example.test' ] )->is_default )->toBeFalse();
    } );

    it( 'restores a token PayPal vaults again instead of duplicating it', function (): void {
        $customer = Customer::factory()->create();
        $token    = $this->vault->save( $customer->id, 'tok-1', 'paypal-card', 'card', [] );

        $token->delete();

        $again = $this->vault->save( $customer->id, 'tok-1', 'paypal-card', 'card', [ 'brand' => 'VISA' ] );

        expect( $again->id )->toBe( $token->id )
            ->and( $again->trashed() )->toBeFalse()
            ->and( $again->display )->toBe( [ 'brand' => 'VISA' ] )
            ->and( PayPalPaymentToken::withTrashed()->count() )->toBe( 1 );
    } );

    it( 'keeps exactly one default per customer and mode', function (): void {
        $customer = Customer::factory()->create();
        $first    = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id ] );
        $second   = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );
        $live     = PayPalPaymentToken::factory()->live()->default()->create( [ 'customer_id' => $customer->id ] );
        $other    = PayPalPaymentToken::factory()->default()->create();

        $this->vault->makeDefault( $second );

        expect( $second->is_default )->toBeTrue()
            ->and( $first->fresh()->is_default )->toBeFalse()
            ->and( $live->fresh()->is_default )->toBeTrue()
            ->and( $other->fresh()->is_default )->toBeTrue()
            ->and( $this->vault->defaultFor( $customer->id )?->id )->toBe( $second->id )
            ->and( PayPalPaymentToken::query()->forCustomer( $customer->id )->forMode( 'sandbox' )->where( 'is_default', true )->count() )->toBe( 1 );
    } );

    it( 'keeps only display data', function ( string $source, array $details, array $expected ): void {
        expect( PayPalVault::display( $source, $details ) )->toBe( $expected );
    } )->with( [
        'card'   => [ 'card', [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12', 'name' => 'Ada', 'number' => '4111111111111111' ], [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ] ],
        'paypal' => [ 'paypal', [ 'email_address' => 'ada@example.test', 'account_id' => 'X', 'name' => [ 'given_name' => 'Ada' ] ], [ 'email' => 'ada@example.test' ] ],
        'venmo'  => [ 'venmo', [ 'email_address' => 'ada@example.test', 'user_name' => 'ada' ], [ 'email' => 'ada@example.test' ] ],
        'empty'  => [ 'card', [ 'brand' => '' ], [] ],
    ] );
} );

describe( 'customer deletion', function (): void {
    it( 'erases the customer\'s vault rows and deletes the tokens at PayPal', function (): void {
        Queue::fake();

        $customer = Customer::factory()->create();
        $kept     = PayPalPaymentToken::factory()->create();

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id ] );
        PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-a' ] );
        PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-b' ] )->delete();
        PayPalPaymentToken::factory()->live()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-live' ] );

        app( CustomerService::class )->delete( $customer );

        expect( PayPalPaymentToken::withTrashed()->pluck( 'id' )->all() )->toBe( [ $kept->id ] )
            ->and( PayPalCustomer::query()->count() )->toBe( 0 );

        Queue::assertPushed( DeletePayPalVaultTokens::class, fn ( DeletePayPalVaultTokens $job ): bool => [ 'tok-a', 'tok-b' ] === $job->tokenIds && 'sandbox' === $job->mode );
    } );

    it( 'deletes the tokens at PayPal when the job runs, treating gone tokens as deleted', function (): void {
        $this->paypal->error( 'vault.payment_tokens.delete', 'resource-not-found' );

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b' ], 'sandbox' ) )->handle( $this->vault );

        $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 2 );
        $this->paypal->assertSent( 'vault.payment_tokens.delete', fn ( Request $request ): bool => str_ends_with( $request->url(), '/tok-b' ) && 'vault-delete-tok-b' === $request->header( 'PayPal-Request-Id' )[0] );
    } );

    it( 'leaves tokens of another mode alone', function (): void {
        ( new DeletePayPalVaultTokens( [ 'tok-a' ], 'live' ) )->handle( $this->vault );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
    } );

    it( 'retries when PayPal can\'t be reached', function (): void {
        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error' );

        ( new DeletePayPalVaultTokens( [ 'tok-a' ], 'sandbox' ) )->handle( $this->vault );
    } )->throws( ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException::class );
} );

describe( 'uninstall', function (): void {
    it( 'keeps tokens at PayPal without --purge', function (): void {
        PayPalPaymentToken::factory()->create();

        app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), false );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
    } );

    it( 'deletes the current mode\'s tokens at PayPal on --purge', function (): void {
        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-a' ] );
        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-b' ] )->delete();
        PayPalPaymentToken::factory()->live()->create( [ 'paypal_token_id' => 'tok-live' ] );

        app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), true );

        $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 2 );
    } );

    it( 'counts tokens PayPal refuses and carries on', function (): void {
        PayPalPaymentToken::factory()->count( 2 )->create();
        PayPalPaymentToken::factory()->live()->create();

        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error' );

        expect( $this->vault->purge() )->toBe( [ 'deleted' => 1, 'failed' => 1, 'skipped' => 1 ] );
    } );
} );
