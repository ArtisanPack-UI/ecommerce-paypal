<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\EcommercePayPal\Payments\AuthorizationLifecycle;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Tests\Support\PayPalTestOrders as Orders;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.enabled'       => true,
        'artisanpack.ecommerce-paypal.client_id'     => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret' => 'client-secret',
        'artisanpack.ecommerce-paypal.http.retries'  => 0,
    ] );

    Sleep::fake();

    $this->paypal = PayPalFake::fake();
} );

/**
 * A stored order with an uncaptured authorization made on 2026-11-09.
 *
 * @param  array<string, mixed>  $authorization  Overrides for `orders.meta.paypal.authorization`.
 * @param  array<string, mixed>  $paypal         Overrides for `orders.meta.paypal`.
 *
 * @return Order
 */
function heldPayPalOrder( array $authorization = [], array $paypal = [] ): Order
{
    return Orders::create( [], array_merge( [
        'intent'        => 'authorize',
        'authorization' => array_merge( [
            'id'            => Orders::AUTHORIZATION_ID,
            'status'        => 'CREATED',
            'created_at'    => '2026-11-09T12:05:00+00:00',
            'authorized_at' => '2026-11-09T12:05:00+00:00',
            'expires_at'    => '2026-12-08T12:05:00+00:00',
        ], $authorization ),
    ], $paypal ) );
}

/**
 * Runs the lifecycle at `$time`.
 *
 * @param  string  $time  ISO 8601 time.
 *
 * @return array{checked: int, reauthorized: int, expiring: int, failed: int}
 */
function runPayPalLifecycle( string $time ): array
{
    return app( AuthorizationLifecycle::class )->run( CarbonImmutable::parse( $time ) );
}

describe( 'reauthorization', function (): void {
    it( 'reauthorizes an authorization past its 3-day honor period and writes an order note', function (): void {
        $order  = heldPayPalOrder();
        $totals = runPayPalLifecycle( '2026-11-13T13:00:00Z' );

        expect( $totals )->toBe( [ 'checked' => 1, 'reauthorized' => 1, 'expiring' => 0, 'failed' => 0 ] );

        $this->paypal->assertSent( 'payments.authorizations.reauthorize', fn ( Request $request ): bool => str_contains( $request->url(), '/authorizations/' . Orders::AUTHORIZATION_ID . '/reauthorize' )
            && 'reauthorize-' . Orders::AUTHORIZATION_ID === $request->header( 'PayPal-Request-Id' )[0] );

        expect( PayPalOrderMeta::get( $order->fresh(), 'authorization' ) )->toBe( [
            'id'              => '8AA831015G517922L',
            'status'          => 'CREATED',
            'created_at'      => '2026-11-13T12:05:00+00:00',
            'authorized_at'   => '2026-11-09T12:05:00+00:00',
            'expires_at'      => '2026-12-08T12:05:00+00:00',
            'previous_id'     => Orders::AUTHORIZATION_ID,
            'reauthorized_at' => '2026-11-13T13:00:00+00:00',
        ] );

        $note = OrderNote::query()->where( 'order_id', $order->id )->sole();

        expect( $note->body )->toContain( Orders::AUTHORIZATION_ID )->toContain( '8AA831015G517922L' )
            ->and( $note->is_customer_visible )->toBeFalse();
    } );

    it( 'leaves an authorization inside its honor period alone', function (): void {
        heldPayPalOrder();

        expect( runPayPalLifecycle( '2026-11-12T12:00:00Z' )['reauthorized'] )->toBe( 0 );
        $this->paypal->assertNotSent( 'payments.authorizations.reauthorize' );
    } );

    it( 'reauthorizes only once, so a second run on the same day changes nothing', function (): void {
        heldPayPalOrder();

        runPayPalLifecycle( '2026-11-13T13:00:00Z' );
        runPayPalLifecycle( '2026-11-13T18:00:00Z' );
        runPayPalLifecycle( '2026-11-20T13:00:00Z' );

        $this->paypal->assertSentCount( 'payments.authorizations.reauthorize', 1 );
        expect( OrderNote::query()->count() )->toBe( 1 );
    } );

    it( 'skips authorizations that are captured, voided, expired, or not PayPal\'s', function ( Closure $make ): void {
        $make();

        expect( runPayPalLifecycle( '2026-11-15T12:00:00Z' ) )->toBe( [ 'checked' => 0, 'reauthorized' => 0, 'expiring' => 0, 'failed' => 0 ] );
        $this->paypal->assertNotSent( 'payments.authorizations.reauthorize' );
    } )->with( [
        'captured'          => [ fn () => heldPayPalOrder( [], [ 'capture_id' => Orders::CAPTURE_ID ] ) ],
        'status captured'   => [ fn () => heldPayPalOrder( [ 'status' => 'CAPTURED' ] ) ],
        'voided'            => [ fn () => heldPayPalOrder( [ 'status' => 'VOIDED' ] ) ],
        'past its validity' => [ fn () => heldPayPalOrder( [ 'expires_at' => '2026-11-14T12:00:00+00:00' ] ) ],
        'another gateway'   => [ fn () => heldPayPalOrder()->forceFill( [ 'payment_gateway_key' => 'stripe' ] )->save() ],
        'capture intent'    => [ fn () => Orders::create( [], [ 'capture_id' => Orders::CAPTURE_ID ] ) ],
    ] );

    it( 'does not reauthorize an authorization pending review, but still warns before it expires', function (): void {
        heldPayPalOrder( [ 'status' => 'PENDING' ] );

        expect( runPayPalLifecycle( '2026-12-06T13:00:00Z' ) )->toBe( [ 'checked' => 1, 'reauthorized' => 0, 'expiring' => 1, 'failed' => 0 ] );
        $this->paypal->assertNotSent( 'payments.authorizations.reauthorize' );
    } );

    it( 'logs and counts a failed reauthorization without changing the order', function (): void {
        $order = heldPayPalOrder();
        $this->paypal->respond( 'payments.authorizations.reauthorize', Orders::issue( 'REAUTHORIZATION_NOT_ALLOWED' ), 422 );

        expect( runPayPalLifecycle( '2026-11-13T13:00:00Z' )['failed'] )->toBe( 1 )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization.id' ) )->toBe( Orders::AUTHORIZATION_ID )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization.reauthorized_at' ) )->toBeNull();
    } );

    it( 'falls back to 29 days from the authorization when PayPal gave no expiry', function (): void {
        heldPayPalOrder( [ 'expires_at' => null ] );

        expect( runPayPalLifecycle( '2026-12-09T12:00:00Z' )['checked'] )->toBe( 0 )
            ->and( runPayPalLifecycle( '2026-12-07T12:00:00Z' )['expiring'] )->toBe( 1 );
    } );
} );

describe( 'expiry warning', function (): void {
    it( 'fires authorizationExpiring once, 2 days before the authorization expires', function (): void {
        $order = heldPayPalOrder( [ 'reauthorized_at' => '2026-11-13T13:00:00+00:00' ] );
        $calls = [];

        addAction( AuthorizationLifecycle::EXPIRING_HOOK, function ( Order $expiring, CarbonImmutable $expiresAt ) use ( &$calls ): void {
            $calls[] = [ $expiring->id, $expiresAt->toIso8601String() ];
        }, 10, 2 );

        runPayPalLifecycle( '2026-12-06T11:00:00Z' );
        expect( $calls )->toBe( [] );

        expect( runPayPalLifecycle( '2026-12-06T13:00:00Z' )['expiring'] )->toBe( 1 );
        runPayPalLifecycle( '2026-12-07T13:00:00Z' );

        expect( $calls )->toBe( [ [ $order->id, '2026-12-08T12:05:00+00:00' ] ] )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization.expiry_notified_at' ) )->toBe( '2026-12-06T13:00:00+00:00' );
    } );
} );

describe( 'command', function (): void {
    it( 'runs the lifecycle and reports what it did', function (): void {
        heldPayPalOrder();
        $this->travelTo( CarbonImmutable::parse( '2026-11-13T13:00:00Z' ) );

        $this->artisan( 'ecommerce-paypal:authorizations:reauthorize' )
            ->expectsOutputToContain( '1 reauthorized' )
            ->assertSuccessful();
    } );

    it( 'fails when a reauthorization failed, so the scheduler reports it', function (): void {
        heldPayPalOrder();
        $this->travelTo( CarbonImmutable::parse( '2026-11-13T13:00:00Z' ) );
        $this->paypal->error( 'payments.authorizations.reauthorize', 'internal-server-error' );

        $this->artisan( 'ecommerce-paypal:authorizations:reauthorize' )->assertFailed();
    } );

    it( 'does nothing while PayPal is disabled', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.enabled' => false ] );
        heldPayPalOrder();
        $this->travelTo( CarbonImmutable::parse( '2026-11-13T13:00:00Z' ) );

        $this->artisan( 'ecommerce-paypal:authorizations:reauthorize' )->assertSuccessful();
        $this->paypal->assertNotSent( 'payments.authorizations.reauthorize' );
    } );

    it( 'is scheduled daily', function (): void {
        $events = array_values( array_filter(
            app( Schedule::class )->events(),
            fn ( $event ): bool => str_contains( (string) $event->command, 'ecommerce-paypal:authorizations:reauthorize' ),
        ) );

        expect( $events )->toHaveCount( 1 )
            ->and( $events[0]->expression )->toBe( '0 0 * * *' );
    } );
} );
