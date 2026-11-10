<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Tests\Support\PayPalTestOrders as Orders;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'     => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret' => 'client-secret',
        'artisanpack.ecommerce-paypal.http.retries'  => 0,
    ] );

    Sleep::fake();

    $this->paypal = PayPalFake::fake();
} );

/**
 * A stored order holding the fixture authorization.
 *
 * @param  string  $status  Stored authorization status.
 *
 * @return ArtisanPackUI\Ecommerce\Models\Order
 */
function authorizedPayPalOrder( string $status = 'CREATED' ): ArtisanPackUI\Ecommerce\Models\Order
{
    return Orders::create( [], [ 'intent' => 'authorize', 'authorization' => [ 'id' => Orders::AUTHORIZATION_ID, 'status' => $status ] ] );
}

it( 'voids the authorization idempotently and records it', function (): void {
    $order = authorizedPayPalOrder();

    app( PayPalWalletGateway::class )->voidPendingPayment( $order );

    $this->paypal->assertSent( 'payments.authorizations.void', fn ( Request $request ): bool => str_contains( $request->url(), '/authorizations/' . Orders::AUTHORIZATION_ID . '/void' )
        && 'void-' . Orders::AUTHORIZATION_ID === $request->header( 'PayPal-Request-Id' )[0] );

    expect( PayPalOrderMeta::get( $order->fresh(), 'authorization.status' ) )->toBe( 'VOIDED' );
} );

it( 'is a no-op the second time (engine contract: double void)', function (): void {
    $order   = authorizedPayPalOrder();
    $gateway = app( PayPalWalletGateway::class );

    $gateway->voidPendingPayment( $order );

    $this->paypal->respond( 'payments.authorizations.get', 'payments/authorization-voided' );
    $gateway->voidPendingPayment( $order->fresh() );

    $this->paypal->assertSentCount( 'payments.authorizations.void', 1 );
} );

it( 'is a no-op for an authorization that is already gone', function ( Closure $state ): void {
    $state( $this->paypal );

    app( PayPalWalletGateway::class )->voidPendingPayment( authorizedPayPalOrder() );

    $this->paypal->assertNotSent( 'payments.authorizations.void' );
} )->with( [
    'voided'    => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'VOIDED' ] ) ) ],
    'expired'   => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'EXPIRED' ] ) ) ],
    'denied'    => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'DENIED' ] ) ) ],
    'not found' => [ fn ( PayPalFake $paypal ) => $paypal->error( 'payments.authorizations.get', 'resource-not-found' ) ],
] );

it( 'treats PayPal reporting the void already happened as a no-op', function ( string $issue ): void {
    $this->paypal->respond( 'payments.authorizations.void', Orders::issue( $issue ), 422 );

    app( PayPalWalletGateway::class )->voidPendingPayment( authorizedPayPalOrder() );
} )->with( [ 'AUTHORIZATION_VOIDED', 'AUTHORIZATION_ALREADY_VOIDED', 'AUTHORIZATION_EXPIRED' ] )->throwsNoExceptions();

it( 'is a no-op when nothing was authorized (intent capture)', function (): void {
    app( PayPalWalletGateway::class )->voidPendingPayment( Orders::create() );

    $this->paypal->assertSentCount( 'orders.get', 1 );
    $this->paypal->assertNotSent( 'payments.authorizations.get' );
    $this->paypal->assertNotSent( 'payments.authorizations.void' );
} );

it( 'is a no-op when the order has no PayPal payment, or the Order is gone', function (): void {
    $gateway = app( PayPalWalletGateway::class );

    $gateway->voidPendingPayment( Orders::create( [ 'payment_reference' => null ] ) );

    $this->paypal->error( 'orders.get', 'resource-not-found' );
    $gateway->voidPendingPayment( Orders::create() );

    $this->paypal->assertNotSent( 'payments.authorizations.void' );
} );

it( 'finds the authorization on the PayPal Order when it was never recorded', function (): void {
    $this->paypal->respond( 'orders.get', 'orders/authorized' );

    app( PayPalWalletGateway::class )->voidPendingPayment( Orders::create() );

    $this->paypal->assertSentCount( 'payments.authorizations.void', 1 );
} );

it( 'throws when the payment was captured, since it needs a refund', function ( Closure $state ): void {
    $state( $this->paypal );

    app( PayPalWalletGateway::class )->voidPendingPayment( authorizedPayPalOrder() );
} )->with( [
    'captured'                  => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'CAPTURED' ] ) ) ],
    'partially captured'        => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'PARTIALLY_CAPTURED' ] ) ) ],
    'captured during the void'  => [ fn ( PayPalFake $paypal ) => $paypal->error( 'payments.authorizations.void', 'authorization-already-captured' ) ],
] )->throws( RuntimeException::class, 'refund it instead' );

it( 'throws when a capture-intent Order was captured', function (): void {
    $this->paypal->respond( 'orders.get', 'orders/captured' );

    app( PayPalWalletGateway::class )->voidPendingPayment( Orders::create() );
} )->throws( RuntimeException::class, 'refund it instead' );

it( 'throws when the outcome is unknown', function ( Closure $state, string $exception ): void {
    $state( $this->paypal );

    expect( fn () => app( PayPalWalletGateway::class )->voidPendingPayment( authorizedPayPalOrder() ) )->toThrow( $exception );
} )->with( [
    'void not confirmed'     => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.void', Orders::body( 'payments/authorization', [ 'status' => 'CREATED' ] ) ), RuntimeException::class ],
    'unexpected state'       => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.get', Orders::body( 'payments/authorization', [ 'status' => 'SOMETHING_NEW' ] ) ), RuntimeException::class ],
    'void refused'           => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.void', Orders::issue( 'PERMISSION_DENIED' ), 422 ), PayPalApiException::class ],
    'void timed out'         => [ fn ( PayPalFake $paypal ) => $paypal->timeout( 'payments.authorizations.void' ), PayPalApiException::class ],
    'lookup failed'          => [ fn ( PayPalFake $paypal ) => $paypal->error( 'payments.authorizations.get', 'internal-server-error' ), PayPalApiException::class ],
] );

it( 'confirms the void with a lookup when PayPal returns no body', function (): void {
    $this->paypal->respond( 'payments.authorizations.void', [], 204 )
        ->respond( 'payments.authorizations.get', 'payments/authorization' )
        ->respond( 'payments.authorizations.get', 'payments/authorization-voided' );

    app( PayPalWalletGateway::class )->voidPendingPayment( authorizedPayPalOrder() );

    $this->paypal->assertSentCount( 'payments.authorizations.get', 2 );
} );

it( 'records an outcome PayPal confirmed through an error, so the lifecycle job stops', function ( Closure $state, string $status ): void {
    $state( $this->paypal );
    $order = authorizedPayPalOrder();

    app( PayPalWalletGateway::class )->voidPendingPayment( $order );

    expect( PayPalOrderMeta::get( $order->fresh(), 'authorization.status' ) )->toBe( $status );
} )->with( [
    'already voided'   => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.void', Orders::issue( 'AUTHORIZATION_ALREADY_VOIDED' ), 422 ), 'VOIDED' ],
    'expired'          => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'payments.authorizations.void', Orders::issue( 'AUTHORIZATION_EXPIRED' ), 422 ), 'EXPIRED' ],
    'gone at void'     => [ fn ( PayPalFake $paypal ) => $paypal->error( 'payments.authorizations.void', 'resource-not-found' ), 'NOT_FOUND' ],
    'gone at lookup'   => [ fn ( PayPalFake $paypal ) => $paypal->error( 'payments.authorizations.get', 'resource-not-found' ), 'NOT_FOUND' ],
] );

it( 'voids the authorization of the Order being released, not one recorded for an earlier attempt', function (): void {
    $this->paypal->respond( 'orders.get', 'orders/authorized' );
    $order = Orders::create( [], [ 'order_id' => 'EARLIER-ORDER', 'authorization' => [ 'id' => 'EARLIER-AUTH', 'status' => 'VOIDED' ] ] );

    app( PayPalWalletGateway::class )->voidPendingPayment( $order );

    $this->paypal->assertSent( 'payments.authorizations.void', fn ( Request $request ): bool => str_contains( $request->url(), '/authorizations/' . Orders::AUTHORIZATION_ID . '/void' ) );
} );
