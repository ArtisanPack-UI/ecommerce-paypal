<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Jobs\DeletePayPalVaultTokens;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    Queue::fake();

    $this->paypal = PayPalFake::fake();
} );

it( 'erases orphaned vault rows and prunes old removed methods', function (): void {
    $customer = Customer::factory()->create();
    $active   = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );
    $recent   = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );
    $old      = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

    PayPalPaymentToken::factory()->create( [ 'customer_id' => 9001, 'paypal_token_id' => 'tok-orphan' ] );

    $this->travelTo( now()->subDays( 31 ) );
    $old->delete();
    $this->travelBack();
    $recent->delete();

    $this->artisan( 'ecommerce-paypal:prune-vault' )
        ->expectsOutputToContain( 'Deleted customers whose saved payment methods were erased: 1. Removed payment methods pruned: 1.' )
        ->assertSuccessful();

    expect( PayPalPaymentToken::withTrashed()->pluck( 'id' )->sort()->values()->all() )->toBe( [ $active->id, $recent->id ] );

    Queue::assertPushed( DeletePayPalVaultTokens::class, fn ( DeletePayPalVaultTokens $job ): bool => [ 'tok-orphan' ] === $job->tokenIds );
} );

it( 'keeps removed methods for the configured number of days', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.vault.prune_after_days' => 90 ] );

    $token = PayPalPaymentToken::factory()->create();

    $this->travelTo( now()->subDays( 31 ) );
    $token->delete();
    $this->travelBack();

    $this->artisan( 'ecommerce-paypal:prune-vault' )->assertSuccessful();

    expect( PayPalPaymentToken::withTrashed()->count() )->toBe( 1 );
} );

it( 'does nothing when the vault tables aren\'t installed', function (): void {
    Schema::drop( 'ecommerce_paypal_payment_tokens' );

    $this->artisan( 'ecommerce-paypal:prune-vault' )
        ->expectsOutputToContain( 'The PayPal vault tables aren\'t installed; there is nothing to prune.' )
        ->assertSuccessful();
} );

it( 'is scheduled daily without overlapping', function (): void {
    $events = array_values( array_filter(
        app( Schedule::class )->events(),
        fn ( $event ): bool => str_contains( (string) $event->command, 'ecommerce-paypal:prune-vault' ),
    ) );

    expect( $events )->toHaveCount( 1 )
        ->and( $events[0]->expression )->toBe( '0 0 * * *' )
        ->and( $events[0]->withoutOverlapping )->toBeTrue();
} );
