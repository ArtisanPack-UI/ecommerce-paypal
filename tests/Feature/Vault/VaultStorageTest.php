<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use ArtisanPackUI\Ecommerce\Services\CustomerService;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Jobs\DeletePayPalVaultTokens;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

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

/**
 * Sends the package's log to a test channel.
 *
 * @return void
 */
function vaultLogToTestChannel(): void
{
    config( [
        'artisanpack.ecommerce-paypal.logging.channel' => 'paypal-vault-test',
        'logging.channels.paypal-vault-test'           => [ 'driver' => 'monolog', 'handler' => TestHandler::class ],
    ] );
}

/**
 * The records logged to the test channel, optionally only those whose
 * message contains `$message`.
 *
 * @param  string|null  $message  Message fragment.
 *
 * @return array<int, LogRecord>
 */
function vaultLogRecords( ?string $message = null ): array
{
    $records = Log::channel( 'paypal-vault-test' )->getLogger()->getHandlers()[0]->getRecords();

    return array_values( array_filter( $records, static fn ( LogRecord $record ): bool => null === $message || str_contains( $record->message, $message ) ) );
}

/**
 * A PayPal Order that vaulted `$tokenId` for `$customerId`'s order, and
 * that order.
 *
 * @param  int     $customerId  Engine customer id.
 * @param  string  $tokenId     PayPal token id.
 * @param  string  $status      The vault status.
 *
 * @return array{0: Order, 1: PayPalOrder}
 */
function vaultedOrder( int $customerId, string $tokenId, string $status = 'VAULTED' ): array
{
    $body = PayPalFake::fixture( 'orders/captured' );

    $body['payment_source']['paypal']['attributes']['vault'] = [ 'id' => $tokenId, 'status' => $status, 'customer' => [ 'id' => 'PAYPAL-CUS' ] ];

    return [ Tests\Support\PayPalTestOrders::create( [ 'customer_id' => $customerId ] ), PayPalOrder::fromArray( $body ) ];
}

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
            ->and( $token->last_used_at )->toBeInstanceOf( Carbon::class )
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

    // save() restores for its direct callers; recordFromOrder() never does.
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

    it( 'leaves a token stored for another customer alone and logs it', function (): void {
        vaultLogToTestChannel();

        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $token = $this->vault->save( $owner->id, 'tok-1', 'paypal-card', 'card', [ 'brand' => 'VISA' ] );

        $again = $this->vault->save( $other->id, 'tok-1', 'paypal', 'paypal', [ 'email' => 'other@example.test' ], 'OTHER-CUS' );

        expect( $again->id )->toBe( $token->id )
            ->and( $token->fresh()->only( [ 'customer_id', 'type', 'display' ] ) )->toBe( [ 'customer_id' => $owner->id, 'type' => 'card', 'display' => [ 'brand' => 'VISA' ] ] )
            ->and( $this->vault->paypalCustomerId( $other->id ) )->toBeNull()
            ->and( vaultLogRecords() )->toHaveCount( 1 )
            ->and( vaultLogRecords()[0]->level->getName() )->toBe( 'WARNING' )
            ->and( json_encode( vaultLogRecords()[0]->context ) )->not->toContain( 'tok-1' );
    } );

    it( 'refuses to make a token the default once it was removed, keeping the old default', function (): void {
        $customer = Customer::factory()->create();
        $default  = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id ] );
        $stale    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        PayPalPaymentToken::query()->whereKey( $stale->id )->sole()->delete();

        expect( fn () => $this->vault->makeDefault( $stale ) )->toThrow( ModelNotFoundException::class )
            ->and( $default->fresh()->is_default )->toBeTrue()
            ->and( PayPalPaymentToken::withTrashed()->find( $stale->id )->is_default )->toBeFalse();
    } );

    it( 'promotes the next token when a stale copy of the default is forgotten', function (): void {
        $customer = Customer::factory()->create();
        $stale    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );
        $next     = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        // Another request made it the default after $stale was loaded.
        $this->vault->makeDefault( PayPalPaymentToken::query()->findOrFail( $stale->id ) );

        $this->vault->forget( $stale );

        expect( $stale->trashed() )->toBeTrue()
            ->and( PayPalPaymentToken::withTrashed()->find( $stale->id )->is_default )->toBeFalse()
            ->and( $next->fresh()->is_default )->toBeTrue();
    } );

    it( 'does nothing when forgetting a token that is already gone', function (): void {
        $customer = Customer::factory()->create();
        $token    = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id ] );
        $other    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        $this->vault->forget( PayPalPaymentToken::query()->findOrFail( $token->id ) );
        $this->vault->makeDefault( PayPalPaymentToken::query()->findOrFail( $other->id ) );
        $deletedAt = PayPalPaymentToken::withTrashed()->find( $token->id )->deleted_at;

        $this->travel( 1 )->minutes();
        $this->vault->forget( $token );

        expect( PayPalPaymentToken::withTrashed()->find( $token->id )->deleted_at->equalTo( $deletedAt ) )->toBeTrue()
            ->and( $other->fresh()->is_default )->toBeTrue();
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

describe( 'recording from a PayPal Order', function (): void {
    it( 'stores the vaulted method of the Order', function (): void {
        $customer             = Customer::factory()->create();
        [ $order, $paypal ]   = vaultedOrder( $customer->id, 'tok-new' );

        $token = $this->vault->recordFromOrder( $order, $paypal, 'paypal' );

        expect( $token?->paypal_token_id )->toBe( 'tok-new' )
            ->and( $token->is_default )->toBeTrue();
    } );

    it( 'never brings back a method the shopper removed when the same Order is recorded again', function (): void {
        $customer           = Customer::factory()->create();
        [ $order, $paypal ] = vaultedOrder( $customer->id, 'tok-removed' );

        $this->vault->forget( $this->vault->recordFromOrder( $order, $paypal, 'paypal' ) );

        expect( $this->vault->recordFromOrder( $order, $paypal, 'paypal' ) )->toBeNull()
            ->and( PayPalPaymentToken::withTrashed()->sole()->trashed() )->toBeTrue()
            ->and( $this->vault->defaultFor( $customer->id ) )->toBeNull();
    } );

    it( 'leaves a stored method as it is, last use included, when the Order is recorded again', function (): void {
        $customer           = Customer::factory()->create();
        [ $order, $paypal ] = vaultedOrder( $customer->id, 'tok-kept' );

        $this->travelTo( Carbon::parse( '2026-11-01 10:00:00' ) );
        $token = $this->vault->recordFromOrder( $order, $paypal, 'paypal' );

        $this->travelTo( Carbon::parse( '2026-11-05 10:00:00' ) );
        $again = $this->vault->recordFromOrder( $order, $paypal, 'paypal' );

        expect( $again?->id )->toBe( $token->id )
            ->and( $token->fresh()->last_used_at->toDateTimeString() )->toBe( '2026-11-01 10:00:00' );
    } );

    it( 'stores nothing until PayPal reports the method vaulted', function (): void {
        [ $order, $paypal ] = vaultedOrder( Customer::factory()->create()->id, 'tok-approved', 'APPROVED' );

        expect( $this->vault->recordFromOrder( $order, $paypal, 'paypal' ) )->toBeNull()
            ->and( PayPalPaymentToken::withTrashed()->count() )->toBe( 0 );
    } );
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

    it( 'leaves tokens of another mode alone, with a warning', function (): void {
        vaultLogToTestChannel();

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b' ], 'live' ) )->handle( $this->vault );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );

        expect( vaultLogRecords() )->toHaveCount( 1 )
            ->and( vaultLogRecords()[0]->level->getName() )->toBe( 'WARNING' )
            ->and( vaultLogRecords()[0]->context )->toMatchArray( [ 'mode' => 'live', 'current_mode' => 'sandbox', 'count' => 2 ] )
            ->and( json_encode( vaultLogRecords()[0]->context ) )->not->toContain( 'tok-a' );
    } );

    it( 'goes on to the next token when PayPal refuses one for good, and logs it', function (): void {
        vaultLogToTestChannel();

        $this->paypal->error( 'vault.payment_tokens.delete', 'invalid-request' );

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b' ], 'sandbox' ) )->handle( $this->vault );

        $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 2 );

        $records = vaultLogRecords( 'refused to delete' );

        expect( $records )->toHaveCount( 1 )
            ->and( $records[0]->level->getName() )->toBe( 'ERROR' )
            ->and( $records[0]->context )->toMatchArray( [ 'token_id' => '[redacted]', 'status' => 400, 'debug_id' => PayPalFake::DEBUG_ID ] )
            ->and( json_encode( array_map( static fn ( LogRecord $record ): array => $record->toArray(), vaultLogRecords() ) ) )->not->toContain( 'tok-a' );
    } );

    it( 'sends only the tokens PayPal couldn\'t be reached for again, in a later round', function (): void {
        Queue::fake();

        $this->paypal->respond( 'vault.payment_tokens.delete', [], 204 );
        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error' );

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b', 'tok-c' ], 'sandbox' ) )->handle( $this->vault );

        $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 3 );

        Queue::assertPushed( DeletePayPalVaultTokens::class, 1 );
        Queue::assertPushed( DeletePayPalVaultTokens::class, fn ( DeletePayPalVaultTokens $job ): bool => [ 'tok-b' ] === $job->tokenIds && 'sandbox' === $job->mode && 2 === $job->round && null !== $job->delay );
    } );

    it( 'gives up after the last round and logs how many tokens are left', function (): void {
        Queue::fake();
        vaultLogToTestChannel();

        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error', times: 2 );

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b' ], 'sandbox', DeletePayPalVaultTokens::MAX_ROUNDS ) )->handle( $this->vault );

        Queue::assertNothingPushed();

        $records = vaultLogRecords( 'Gave up' );

        expect( $records )->toHaveCount( 1 )
            ->and( $records[0]->level->getName() )->toBe( 'CRITICAL' )
            ->and( $records[0]->context )->toMatchArray( [ 'mode' => 'sandbox', 'count' => 2, 'debug_id' => PayPalFake::DEBUG_ID ] );
    } );

    it( 'logs a job that failed', function (): void {
        vaultLogToTestChannel();

        ( new DeletePayPalVaultTokens( [ 'tok-a', 'tok-b', 'tok-c' ], 'live' ) )->failed( new PayPalApiException( 'PayPal is down.', 503, debugId: 'debug-1' ) );

        expect( vaultLogRecords() )->toHaveCount( 1 )
            ->and( vaultLogRecords()[0]->level->getName() )->toBe( 'CRITICAL' )
            ->and( vaultLogRecords()[0]->context )->toMatchArray( [ 'mode' => 'live', 'count' => 3, 'debug_id' => 'debug-1' ] );
    } );

    it( 'encrypts the job, which holds PayPal token ids', function (): void {
        expect( new DeletePayPalVaultTokens( [ 'tok-a' ], 'sandbox' ) )->toBeInstanceOf( ShouldBeEncrypted::class );
    } );

    it( 'warns when the tokens can\'t be deleted at PayPal without credentials', function (): void {
        Queue::fake();
        vaultLogToTestChannel();
        config( [ 'artisanpack.ecommerce-paypal.client_secret' => null ] );

        $customer = Customer::factory()->create();
        PayPalPaymentToken::factory()->count( 2 )->create( [ 'customer_id' => $customer->id ] );

        $this->vault->forgetCustomer( $customer->id );

        Queue::assertNothingPushed();

        expect( PayPalPaymentToken::withTrashed()->count() )->toBe( 0 )
            ->and( vaultLogRecords() )->toHaveCount( 1 )
            ->and( vaultLogRecords()[0]->level->getName() )->toBe( 'WARNING' )
            ->and( vaultLogRecords()[0]->context )->toMatchArray( [ 'mode' => 'sandbox', 'count' => 2 ] );
    } );
} );

describe( 'orphans and pruning', function (): void {
    it( 'erases the vault rows of customers the engine no longer has', function (): void {
        Queue::fake();

        $customer = Customer::factory()->create();
        $kept     = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id ] );
        PayPalPaymentToken::factory()->create( [ 'customer_id' => 9001, 'paypal_token_id' => 'tok-orphan' ] );
        PayPalPaymentToken::factory()->create( [ 'customer_id' => 9001, 'paypal_token_id' => 'tok-orphan-removed' ] )->delete();
        PayPalCustomer::factory()->create( [ 'customer_id' => 9002 ] );

        expect( $this->vault->forgetOrphans() )->toBe( 2 )
            ->and( PayPalPaymentToken::withTrashed()->pluck( 'id' )->all() )->toBe( [ $kept->id ] )
            ->and( PayPalCustomer::query()->pluck( 'customer_id' )->all() )->toBe( [ $customer->id ] );

        Queue::assertPushed( DeletePayPalVaultTokens::class, 1 );
        Queue::assertPushed( DeletePayPalVaultTokens::class, fn ( DeletePayPalVaultTokens $job ): bool => [ 'tok-orphan', 'tok-orphan-removed' ] === $job->tokenIds );
    } );

    it( 'finds no orphans when every customer exists', function (): void {
        Queue::fake();

        PayPalPaymentToken::factory()->count( 2 )->create();
        PayPalCustomer::factory()->create();

        expect( $this->vault->forgetOrphans() )->toBe( 0 )
            ->and( PayPalPaymentToken::query()->count() )->toBe( 2 );

        Queue::assertNothingPushed();
    } );

    it( 'keeps the 30-day window when the configured one isn\'t a number', function ( mixed $configured, int $days ): void {
        config( [ 'artisanpack.ecommerce-paypal.vault.prune_after_days' => $configured ] );

        expect( PayPalPaymentToken::pruneAfterDays() )->toBe( $days );
    } )->with( [
        'a typo'   => [ 'abc', 30 ],
        'empty'    => [ '', 30 ],
        'a string' => [ '7', 7 ],
        'negative' => [ -5, 0 ],
    ] );

    it( 'prunes removed tokens once the retention window has passed', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.vault.prune_after_days' => 30 ] );

        $old    = PayPalPaymentToken::factory()->create();
        $recent = PayPalPaymentToken::factory()->create();
        $active = PayPalPaymentToken::factory()->create( [ 'updated_at' => now()->subYear() ] );

        $this->travelTo( now()->subDays( 31 ) );
        $old->delete();
        $this->travelBack();
        $recent->delete();

        expect( ( new PayPalPaymentToken() )->pruneAll() )->toBe( 1 )
            ->and( PayPalPaymentToken::withTrashed()->pluck( 'id' )->sort()->values()->all() )->toBe( [ $recent->id, $active->id ] );
    } );
} );

describe( 'uninstall', function (): void {
    it( 'keeps tokens at PayPal without --purge', function (): void {
        PayPalPaymentToken::factory()->create();

        app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), false );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
    } );

    it( 'deletes the current mode\'s tokens at PayPal on --purge, skipping removed ones', function (): void {
        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-a' ] );
        PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => 'tok-b' ] )->delete();

        app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), true );

        $this->paypal->assertSentCount( 'vault.payment_tokens.delete', 1 );
        $this->paypal->assertSent( 'vault.payment_tokens.delete', fn ( Request $request ): bool => str_ends_with( $request->url(), '/tok-a' ) );
    } );

    it( 'makes no PayPal call for removed tokens', function (): void {
        PayPalPaymentToken::factory()->create()->delete();

        expect( $this->vault->purge() )->toBe( [ 'deleted' => 0, 'failed' => 0, 'skipped' => 0 ] );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
    } );

    it( 'stops the purge, keeping the rows, when PayPal can\'t delete a token', function (): void {
        PayPalPaymentToken::factory()->count( 2 )->create();

        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error' );

        expect( fn () => app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), true ) )
            ->toThrow( RuntimeException::class, 'PayPal couldn\'t delete 1 saved payment tokens, so nothing was purged. Run the uninstall again.' )
            ->and( PayPalPaymentToken::query()->count() )->toBe( 2 );
    } );

    it( 'warns about the other mode\'s tokens on --purge', function (): void {
        vaultLogToTestChannel();

        PayPalPaymentToken::factory()->live()->count( 2 )->create();

        app( PayPalUninstaller::class )->uninstall( SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() ), true );

        $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );

        expect( vaultLogRecords() )->toHaveCount( 1 )
            ->and( vaultLogRecords()[0]->level->getName() )->toBe( 'WARNING' )
            ->and( vaultLogRecords()[0]->context )->toBe( [ 'deleted' => 0, 'failed' => 0, 'skipped' => 2 ] );
    } );

    it( 'counts tokens PayPal refuses and carries on', function (): void {
        PayPalPaymentToken::factory()->count( 2 )->create();
        PayPalPaymentToken::factory()->live()->create();

        $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error' );

        expect( $this->vault->purge() )->toBe( [ 'deleted' => 1, 'failed' => 1, 'skipped' => 1 ] );
    } );
} );
