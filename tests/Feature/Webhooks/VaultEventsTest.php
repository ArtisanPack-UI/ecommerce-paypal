<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\Support\PayPalWebhooks as Webhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    Webhooks::boot( [ 'vault.enabled' => true ] );
} );

describe( 'VAULT.PAYMENT-TOKEN.DELETED', function (): void {
    it( 'soft-deletes the local token once PayPal confirms it is gone, promoting another default', function (): void {
        $customer = Customer::factory()->create();
        $token    = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => 'tok-gone' ] );
        $other    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id ] );

        $this->paypal->error( 'vault.payment_tokens.get', 'resource-not-found' );

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.DELETED', [ 'id' => 'tok-gone' ] )->assertOk();

        expect( $token->refresh()->trashed() )->toBeTrue()
            ->and( $other->refresh()->is_default )->toBeTrue();
    } );

    it( 'ignores a deletion PayPal doesn\'t confirm, logging no token id', function (): void {
        config( [
            'artisanpack.ecommerce-paypal.logging.channel' => 'paypal-vault-event-test',
            'logging.channels.paypal-vault-event-test'     => [ 'driver' => 'monolog', 'handler' => TestHandler::class ],
        ] );

        $token = PayPalPaymentToken::factory()->create( [ 'paypal_token_id' => '8kk8451t' ] );

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.DELETED', [ 'id' => '8kk8451t' ] )->assertOk();

        $records = array_values( array_filter(
            Log::channel( 'paypal-vault-event-test' )->getLogger()->getHandlers()[0]->getRecords(),
            static fn ( LogRecord $record ): bool => str_contains( $record->message, 'PayPal still has' ),
        ) );

        expect( $token->refresh()->trashed() )->toBeFalse()
            ->and( $records )->toHaveCount( 1 )
            ->and( $records[0]->context )->toBe( [ 'token_id' => '[redacted]' ] );
    } );

    it( 'leaves the other mode\'s tokens alone', function (): void {
        $token = PayPalPaymentToken::factory()->live()->create( [ 'paypal_token_id' => 'tok-live' ] );

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.DELETED', [ 'id' => 'tok-live' ] )->assertOk();

        expect( $token->refresh()->trashed() )->toBeFalse();

        $this->paypal->assertNotSent( 'vault.payment_tokens.get' );
    } );
} );

describe( 'VAULT.PAYMENT-TOKEN.CREATED', function (): void {
    it( 'stores a token saved elsewhere for a known PayPal customer, from the re-fetched token', function (): void {
        $customer = Customer::factory()->create();

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'customer_4029352050' ] );

        // The body claims other card details; only PayPal's answer counts.
        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => '8kk8451t', 'payment_source' => [ 'card' => [ 'last_digits' => '0000' ] ] ] )->assertOk();

        $token = PayPalPaymentToken::query()->sole();

        expect( $token->only( [ 'customer_id', 'paypal_token_id', 'gateway_key', 'type', 'display', 'mode', 'is_default' ] ) )->toBe( [
            'customer_id'     => $customer->id,
            'paypal_token_id' => '8kk8451t',
            'gateway_key'     => 'paypal-card',
            'type'            => 'card',
            'display'         => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ],
            'mode'            => 'sandbox',
            'is_default'      => true,
        ] )->and( $token->last_used_at )->toBeNull();
    } );

    it( 'ignores tokens of PayPal customers the store doesn\'t know', function (): void {
        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => '8kk8451t' ] )->assertOk();

        expect( PayPalPaymentToken::query()->count() )->toBe( 0 );
    } );

    it( 'doesn\'t bring back a token the shopper removed, or touch one already stored', function (): void {
        $customer = Customer::factory()->create();

        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'customer_4029352050' ] );
        $token = PayPalPaymentToken::factory()->create( [ 'customer_id' => $customer->id, 'paypal_token_id' => '8kk8451t', 'last_used_at' => '2026-01-01 00:00:00' ] );
        $token->delete();

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => '8kk8451t' ] )->assertOk();

        expect( $token->refresh()->trashed() )->toBeTrue()
            ->and( PayPalPaymentToken::withTrashed()->count() )->toBe( 1 );
    } );

    it( 'does nothing while vaulting is off', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

        $customer = Customer::factory()->create();
        PayPalCustomer::factory()->create( [ 'customer_id' => $customer->id, 'paypal_customer_id' => 'customer_4029352050' ] );

        Webhooks::post( $this, 'VAULT.PAYMENT-TOKEN.CREATED', [ 'id' => '8kk8451t' ] )->assertOk();

        expect( PayPalPaymentToken::query()->count() )->toBe( 0 );
    } );
} );
