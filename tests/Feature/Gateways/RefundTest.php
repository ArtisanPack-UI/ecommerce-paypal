<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Money\Currency;
use Money\Money;
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
 * A captured order.
 *
 * @return ArtisanPackUI\Ecommerce\Models\Order
 */
function capturedPayPalOrder(): ArtisanPackUI\Ecommerce\Models\Order
{
    return Orders::create( [], [ 'intent' => 'capture', 'capture_id' => Orders::CAPTURE_ID ] );
}

/**
 * USD money.
 *
 * @param  int  $amount  Minor amount.
 *
 * @return Money
 */
function usd( int $amount ): Money
{
    return new Money( $amount, new Currency( 'USD' ) );
}

it( 'refunds the capture in full with the engine idempotency key and refund id', function (): void {
    $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'amount.value' => '100.00' ] ) );

    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 10000 ), 'Defective product', [ 'idempotency_key' => 'refund-key-1', 'refund_id' => 7 ] );

    expect( $result->success )->toBeTrue()
        ->and( $result->gatewayReference )->toBe( '1JU08902781691411' )
        ->and( (int) $result->amount->getAmount() )->toBe( 10000 );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => str_contains( $request->url(), '/captures/' . Orders::CAPTURE_ID . '/refund' )
        && 'refund-key-1' === $request->header( 'PayPal-Request-Id' )[0]
        && [ 'currency_code' => 'USD', 'value' => '100.00' ] === $request['amount']
        && 'refund-7' === $request['invoice_id']
        && '7' === $request['custom_id']
        && 'Defective product' === $request['note_to_payer'] );
} );

it( 'refunds part of the capture, and several parts in turn', function (): void {
    $gateway = app( PayPalWalletGateway::class );
    $order   = capturedPayPalOrder();

    $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'amount.value' => '25.00' ] ) )
        ->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'amount.value' => '12.50' ] ) );

    $first  = $gateway->refund( $order, usd( 2500 ), null, [ 'idempotency_key' => 'r-1', 'refund_id' => 1 ] );
    $second = $gateway->refund( $order, usd( 1250 ), null, [ 'idempotency_key' => 'r-2', 'refund_id' => 2 ] );

    $sent = $this->paypal->sent( 'payments.captures.refund' );

    expect( $first->success && $second->success )->toBeTrue()
        ->and( (int) $second->amount->getAmount() )->toBe( 1250 )
        ->and( array_map( fn ( Request $request ): array => [ $request->header( 'PayPal-Request-Id' )[0], $request['amount']['value'], $request['invoice_id'] ], $sent ) )
        ->toBe( [ [ 'r-1', '25.00', 'refund-1' ], [ 'r-2', '12.50', 'refund-2' ] ] )
        ->and( isset( $sent[0]['note_to_payer'] ) )->toBeFalse();
} );

it( 'sends a retried refund with the same request id, so PayPal returns the same refund', function (): void {
    $gateway = app( PayPalWalletGateway::class );
    $order   = capturedPayPalOrder();

    $first  = $gateway->refund( $order, usd( 1000 ), null, [ 'idempotency_key' => 'same-key', 'refund_id' => 3 ] );
    $second = $gateway->refund( $order, usd( 1000 ), null, [ 'idempotency_key' => 'same-key', 'refund_id' => 3 ] );

    expect( $second->gatewayReference )->toBe( $first->gatewayReference )
        ->and( array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'payments.captures.refund' ) ) )
        ->toBe( [ 'same-key', 'same-key' ] );
} );

it( 'derives a stable request id when the engine passes no key', function (): void {
    $gateway = app( PayPalWalletGateway::class );
    $order   = capturedPayPalOrder();

    $gateway->refund( $order, usd( 1000 ) );
    $gateway->refund( $order, usd( 1000 ) );

    expect( array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'payments.captures.refund' ) ) )
        ->toBe( [ 'refund-' . Orders::CAPTURE_ID . '-1-1000', 'refund-' . Orders::CAPTURE_ID . '-1-1000' ] );
} );

it( 'truncates the note to payer to 255 characters', function (): void {
    app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), str_repeat( 'é', 300 ), [ 'idempotency_key' => 'k' ] );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => 255 === mb_strlen( $request['note_to_payer'] ) );
} );

it( 'finds the capture from the engine\'s capture reference or the PayPal Order', function ( Closure $make ): void {
    app( PayPalWalletGateway::class )->refund( $make( $this->paypal ), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => str_contains( $request->url(), '/captures/' . Orders::CAPTURE_ID . '/refund' ) );
} )->with( [
    'engine meta'  => [ fn ( PayPalFake $paypal ) => Orders::create( [ 'meta' => [ 'payment' => [ 'capture_reference' => Orders::CAPTURE_ID ] ] ] ) ],
    'PayPal Order' => [ function ( PayPalFake $paypal ) {
        $paypal->respond( 'orders.get', 'orders/captured' );

        return Orders::create();
    } ],
] );

it( 'succeeds on a pending refund (the webhook confirms it)', function (): void {
    $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'status' => 'PENDING' ] ) );

    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeTrue()
        ->and( $result->gatewayReference )->toBe( '1JU08902781691411' );
} );

it( 'fails a refund PayPal reports as failed or cancelled', function ( string $status ): void {
    $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'status' => $status ] ) );

    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'refund_' . strtolower( $status ) );
} )->with( [ 'FAILED', 'CANCELLED' ] );

it( 'returns a failed result with a translated message for every decline', function ( string $issue, string $message ): void {
    $this->paypal->respond( 'payments.captures.refund', Orders::issue( $issue ), 422 );

    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result )->toBeInstanceOf( RefundResult::class )
        ->and( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( strtolower( $issue ) )
        ->and( $result->errorMessage )->toBe( $message )
        ->and( $result->gatewayReference )->toBeNull();
} )->with( [
    [ 'CAPTURE_FULLY_REFUNDED', 'This payment has already been fully refunded.' ],
    [ 'REFUND_AMOUNT_EXCEEDED', 'The refund is more than what is left to refund on this payment.' ],
    [ 'REFUND_TIME_LIMIT_EXCEEDED', 'This payment is too old for PayPal to refund.' ],
    [ 'REFUND_NOT_ALLOWED', 'PayPal does not allow a refund on this payment.' ],
    [ 'REFUND_NOT_PERMITTED_DUE_TO_CHARGEBACK', 'PayPal does not allow a refund on this payment.' ],
    [ 'PARTIAL_REFUND_NOT_ALLOWED', 'PayPal only allows a full refund on this payment.' ],
    [ 'MAX_NUMBER_OF_REFUNDS_EXCEEDED', 'This payment has reached the number of refunds PayPal allows.' ],
    [ 'REFUND_FAILED_INSUFFICIENT_FUNDS', 'Your PayPal balance does not cover this refund.' ],
    [ 'SOMETHING_NEW', 'PayPal declined the refund.' ],
] );

it( 'maps the recorded decline fixtures', function ( string $fixture, string $code ): void {
    $this->paypal->error( 'payments.captures.refund', $fixture );

    expect( app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] )->errorCode )->toBe( $code );
} )->with( [
    [ 'capture-fully-refunded', 'capture_fully_refunded' ],
    [ 'refund-amount-exceeded', 'refund_amount_exceeded' ],
    [ 'resource-not-found', 'invalid_resource_id' ],
    [ 'internal-server-error', 'provider_unavailable' ],
] );

it( 'does not throw when PayPal cannot be reached', function (): void {
    $this->paypal->timeout( 'payments.captures.refund' );

    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'provider_unavailable' );
} );

it( 'throws on a currency mismatch', function (): void {
    app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), new Money( 1000, new Currency( 'EUR' ) ) );
} )->throws( PaymentCurrencyMismatchException::class );

it( 'refuses to refund an authorization that was never captured, pointing to void', function ( Closure $make ): void {
    $result = app( PayPalWalletGateway::class )->refund( $make( $this->paypal ), usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'not_captured' )
        ->and( $result->errorMessage )->toContain( 'Void the authorization' );
    $this->paypal->assertNotSent( 'payments.captures.refund' );
} )->with( [
    'recorded'  => [ fn ( PayPalFake $paypal ) => Orders::create( [], [ 'intent' => 'authorize', 'authorization' => [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'CREATED' ] ] ) ],
    'at PayPal' => [ function ( PayPalFake $paypal ) {
        $paypal->respond( 'orders.get', 'orders/authorized' );

        return Orders::create();
    } ],
] );

it( 'fails when the order has no PayPal payment', function (): void {
    $result = app( PayPalWalletGateway::class )->refund( Orders::create( [ 'payment_reference' => null ] ), usd( 1000 ) );

    expect( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'missing_payment_reference' );
} );

it( 'reports the amount PayPal refunded, so the engine catches a mismatch', function (): void {
    $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 5000 ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeTrue()
        ->and( (int) $result->amount->getAmount() )->toBe( 1000 );
} );

describe( 'unknown outcome', function (): void {
    it( 'finds a refund PayPal took before the connection failed', function ( string $match ): void {
        $this->paypal->timeout( 'payments.captures.refund' );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [
            'purchase_units.0.payments.refunds' => [ Orders::body( 'payments/refund', [ 'custom_id' => 'custom_id' === $match ? '9' : 'other', 'invoice_id' => 'invoice_id' === $match ? 'refund-9' : 'other' ] ) ],
        ] ) );

        $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k', 'refund_id' => 9 ] );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( '1JU08902781691411' );
    } )->with( [ 'custom_id', 'invoice_id' ] );

    it( 'fails when the refund is not at PayPal', function (): void {
        $this->paypal->error( 'payments.captures.refund', 'internal-server-error' );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [
            'purchase_units.0.payments.refunds' => [ Orders::body( 'payments/refund', [ 'custom_id' => '8', 'invoice_id' => 'refund-8' ] ) ],
        ] ) );

        $result = app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k', 'refund_id' => 9 ] );

        expect( $result->success )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'provider_unavailable' );
    } );

    it( 'does not look for a declined refund', function (): void {
        $this->paypal->error( 'payments.captures.refund', 'capture-fully-refunded' );

        app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), null, [ 'idempotency_key' => 'k', 'refund_id' => 9 ] );

        $this->paypal->assertNotSent( 'orders.get' );
    } );
} );

it( 'ignores a capture recorded for an earlier PayPal Order', function (): void {
    $this->paypal->respond( 'orders.get', 'orders/captured' );
    $order = Orders::create( [], [ 'order_id' => 'EARLIER-ORDER', 'capture_id' => 'EARLIER-CAPTURE' ] );

    app( PayPalWalletGateway::class )->refund( $order, usd( 1000 ), null, [ 'idempotency_key' => 'k' ] );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => str_contains( $request->url(), '/captures/' . Orders::CAPTURE_ID . '/refund' ) );
} );

it( 'refuses a HUF or TWD amount that is not whole before calling PayPal', function ( string $currency ): void {
    $order  = Orders::create( [ 'currency' => $currency ], [ 'intent' => 'capture', 'capture_id' => Orders::CAPTURE_ID ] );
    $result = app( PayPalWalletGateway::class )->refund( $order, new Money( 50, new Currency( $currency ) ), null, [ 'idempotency_key' => 'k', 'refund_id' => 1 ] );

    expect( $result )->toBeInstanceOf( RefundResult::class )
        ->and( $result->success )->toBeFalse()
        ->and( $result->errorCode )->toBe( 'amount_not_representable' )
        ->and( $result->errorMessage )->toBe( 'PayPal only refunds whole ' . $currency . ' amounts.' );

    $this->paypal->assertNotSent( 'payments.captures.refund' );
    $this->paypal->assertNotSent( 'orders.get' );
} )->with( [ 'HUF', 'TWD' ] );

it( 'refunds a whole HUF amount', function (): void {
    $order = Orders::create( [ 'currency' => 'HUF' ], [ 'intent' => 'capture', 'capture_id' => Orders::CAPTURE_ID ] );
    $this->paypal->respond( 'payments.captures.refund', Orders::body( 'payments/refund', [ 'amount' => [ 'currency_code' => 'HUF', 'value' => '50' ] ] ) );

    $result = app( PayPalWalletGateway::class )->refund( $order, new Money( 5000, new Currency( 'HUF' ) ), null, [ 'idempotency_key' => 'k' ] );

    expect( $result->success )->toBeTrue()
        ->and( (int) $result->amount->getAmount() )->toBe( 5000 );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => [ 'currency_code' => 'HUF', 'value' => '50' ] === $request['amount'] );
} );

it( 'never shows the engine\'s reason codes to the buyer', function ( string $reason, ?string $note ): void {
    app( PayPalWalletGateway::class )->refund( capturedPayPalOrder(), usd( 1000 ), $reason, [ 'idempotency_key' => 'k' ] );

    $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => $note === ( $request->data()['note_to_payer'] ?? null ) );
} )->with( [
    'fraudulent'            => [ 'fraudulent', null ],
    'duplicate'             => [ 'duplicate', null ],
    'requested by customer' => [ 'requested_by_customer', 'Refund requested by the customer.' ],
    'free text'             => [ '  Damaged in transit  ', 'Damaged in transit' ],
] );
