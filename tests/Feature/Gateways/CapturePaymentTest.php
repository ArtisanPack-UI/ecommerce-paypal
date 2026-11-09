<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;
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

describe( 'intent capture', function (): void {
    it( 'captures the approved Order with a deterministic request id', function (): void {
        $order  = Orders::create();
        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID )
            ->and( (int) $result->amount->getAmount() )->toBe( 10000 )
            ->and( $result->amount->getCurrency()->getCode() )->toBe( 'USD' );

        $this->paypal->assertSent( 'orders.capture', fn ( Request $request ): bool => 'capture-' . Orders::ORDER_ID === $request->header( 'PayPal-Request-Id' )[0] );
        $this->paypal->assertNotSent( 'orders.authorize' );
    } );

    it( 'saves the capture and payer details to orders.meta.paypal', function (): void {
        $order = Orders::create();

        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( PayPalOrderMeta::all( $order->fresh() ) )->toMatchArray( [
            'order_id'       => Orders::ORDER_ID,
            'intent'         => 'capture',
            'mode'           => 'sandbox',
            'funding_source' => 'paypal',
            'capture_id'     => Orders::CAPTURE_ID,
            'capture_status' => 'COMPLETED',
            'payer'          => [ 'email' => 'buyer@example.com', 'id' => 'QYR5Z8XDVJNXQ' ],
        ] )
            ->and( $order->isDirty( 'meta' ) )->toBeFalse();
    } );

    it( 'keeps meta the engine wrote in the meantime', function (): void {
        $order = Orders::create( [ 'meta' => [ 'finalize_attempt' => [ 'id' => 'attempt-1' ] ] ] );

        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( $order->fresh()->meta['finalize_attempt'] )->toBe( [ 'id' => 'attempt-1' ] );
    } );

    it( 'returns the existing capture for an Order that is already captured, without capturing again', function (): void {
        $order   = Orders::create();
        $gateway = app( PayPalWalletGateway::class );

        $first = $gateway->capturePayment( $order, Orders::session() );

        $this->paypal->respond( 'orders.get', 'orders/captured' );
        $second = $gateway->capturePayment( $order, Orders::session() );

        expect( $second->success )->toBeTrue()
            ->and( $second->gatewayReference )->toBe( $first->gatewayReference )
            ->and( $second->amount->equals( $first->amount ) )->toBeTrue();

        $this->paypal->assertSentCount( 'orders.capture', 1 );
    } );

    it( 'recovers the capture when PayPal says the Order was already captured', function (): void {
        $this->paypal->error( 'orders.capture', 'order-already-captured' );
        $this->paypal->respond( 'orders.get', 'orders/approved' )->respond( 'orders.get', 'orders/captured' );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID );
    } );

    it( 'succeeds on a pending capture and records why it is held', function (): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [
            'purchase_units.0.payments.captures.0.status'         => 'PENDING',
            'purchase_units.0.payments.captures.0.status_details' => [ 'reason' => 'ECHECK' ],
        ] ) );

        $order  = Orders::create();
        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'PENDING' )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'pending_reason' ) )->toBe( 'ECHECK' );
    } );

    it( 'fails terminally on a declined capture', function (): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [ 'purchase_units.0.payments.captures.0.status' => 'DECLINED' ] ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'capture_declined' );
    } );

    it( 'does not record a declined capture as the order\'s capture', function (): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [ 'purchase_units.0.payments.captures.0.status' => 'DECLINED' ] ) );

        $order = Orders::create();
        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( PayPalOrderMeta::get( $order->fresh(), 'capture_id' ) )->toBeNull()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( 'DECLINED' );
    } );
} );

describe( 'intent authorize', function (): void {
    beforeEach( function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'intent' => 'AUTHORIZE' ] ) );
    } );

    it( 'authorizes the Order, then captures the authorization in full', function (): void {
        $order  = Orders::create( [ 'payment_gateway_key' => 'paypal-card' ] );
        $result = app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID );

        $this->paypal->assertSent( 'orders.authorize', fn ( Request $request ): bool => 'authorize-' . Orders::ORDER_ID === $request->header( 'PayPal-Request-Id' )[0] );
        $this->paypal->assertSent( 'payments.authorizations.capture', fn ( Request $request ): bool => str_contains( $request->url(), '/authorizations/' . Orders::AUTHORIZATION_ID . '/capture' )
            && 'capture-' . Orders::AUTHORIZATION_ID === $request->header( 'PayPal-Request-Id' )[0]
            && true === $request['final_capture'] );
        $this->paypal->assertNotSent( 'orders.capture' );
    } );

    it( 'saves the authorization, capture, and card to orders.meta.paypal', function (): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal-card' ] );

        app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );

        expect( PayPalOrderMeta::all( $order->fresh() ) )->toMatchArray( [
            'intent'          => 'authorize',
            'funding_source'  => 'card',
            'liability_shift' => 'POSSIBLE',
            'card'            => [ 'brand' => 'VISA', 'last_digits' => '1111' ],
            'capture_id'      => Orders::CAPTURE_ID,
        ] )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization' ) )->toBe( [
                'id'            => Orders::AUTHORIZATION_ID,
                'status'        => 'CAPTURED',
                'created_at'    => '2026-11-09T12:05:00+00:00',
                'authorized_at' => '2026-11-09T12:05:00+00:00',
                'expires_at'    => '2026-12-08T12:05:00+00:00',
            ] );
    } );

    it( 'replaces what an earlier attempt recorded instead of mixing it in', function (): void {
        $order = Orders::create( [], [
            'order_id'       => 'EARLIER-ORDER',
            'funding_source' => 'venmo',
            'authorization'  => [ 'id' => 'EARLIER-AUTH', 'status' => 'VOIDED', 'authorized_at' => '2026-10-01T00:00:00+00:00', 'expires_at' => '2026-10-30T00:00:00+00:00', 'reauthorized_at' => '2026-10-05T00:00:00+00:00' ],
        ] );

        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        $paypal = PayPalOrderMeta::all( $order->fresh() );

        expect( $paypal['order_id'] )->toBe( Orders::ORDER_ID )
            ->and( $paypal['funding_source'] )->toBe( 'card' )
            ->and( $paypal['authorization'] )->toBe( [
                'id'            => Orders::AUTHORIZATION_ID,
                'status'        => 'CAPTURED',
                'created_at'    => '2026-11-09T12:05:00+00:00',
                'authorized_at' => '2026-11-09T12:05:00+00:00',
                'expires_at'    => '2026-12-08T12:05:00+00:00',
            ] );
    } );

    it( 'keeps the authorization when the capture fails, so it can be voided or retried', function (): void {
        $this->paypal->error( 'payments.authorizations.capture', 'internal-server-error' );

        $order  = Orders::create();
        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeTrue()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization.id' ) )->toBe( Orders::AUTHORIZATION_ID )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'authorization.status' ) )->toBe( 'CREATED' );
    } );

    it( 'captures an Order that was authorized on an earlier attempt without authorizing again', function (): void {
        $this->paypal = PayPalFake::fake();
        $this->paypal->respond( 'orders.get', 'orders/authorized' );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeTrue();
        $this->paypal->assertNotSent( 'orders.authorize' );
        $this->paypal->assertSentCount( 'payments.authorizations.capture', 1 );
    } );

    it( 'returns the existing capture when the authorization was already captured', function (): void {
        $this->paypal->error( 'payments.authorizations.capture', 'authorization-already-captured' );
        $this->paypal->respond( 'orders.get', 'orders/captured' );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID );
    } );

    it( 'fails terminally when the authorization is no longer valid', function ( string $status ): void {
        $this->paypal->respond( 'orders.authorize', Orders::body( 'orders/authorized', [ 'purchase_units.0.payments.authorizations.0.status' => $status ] ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'authorization_' . strtolower( $status ) );
        $this->paypal->assertNotSent( 'payments.authorizations.capture' );
    } )->with( [ 'VOIDED', 'EXPIRED', 'DENIED' ] );

    it( 'retries later while the authorization is pending review', function (): void {
        $this->paypal->respond( 'orders.authorize', Orders::body( 'orders/authorized', [ 'purchase_units.0.payments.authorizations.0.status' => 'PENDING' ] ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->retryable )->toBeTrue()
            ->and( $result->errorCode )->toBe( 'authorization_pending' );
    } );

    it( 'maps an authorize decline', function (): void {
        $this->paypal->error( 'orders.authorize', 'instrument-declined' );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeTrue()
            ->and( $result->errorCode )->toBe( 'instrument_declined' );
    } );
} );

describe( 'declines and failures', function (): void {
    it( 'maps PayPal errors to retryable or terminal results without throwing', function ( Closure $fail, bool $retryable, string $code ): void {
        $fail( $this->paypal );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result )->toBeInstanceOf( PaymentResult::class )
            ->and( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBe( $retryable )
            ->and( $result->errorCode )->toBe( $code )
            ->and( $result->errorMessage )->toBeString()->not->toBeEmpty()
            ->and( (int) $result->amount->getAmount() )->toBe( 10000 );
    } )->with( [
        'instrument declined'   => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'instrument-declined' ), true, 'instrument_declined' ],
        'payer action required' => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'orders.capture', Orders::issue( 'PAYER_ACTION_REQUIRED' ), 422 ), true, 'requires_action' ],
        'other 422'             => [ fn ( PayPalFake $paypal ) => $paypal->respond( 'orders.capture', Orders::issue( 'TRANSACTION_REFUSED' ), 422 ), false, 'transaction_refused' ],
        'bad request'           => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'invalid-request' ), false, 'decimal_precision' ],
        '5xx'                   => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'internal-server-error' ), true, 'provider_unavailable' ],
        'rate limited'          => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.capture', 'rate-limit' ), true, 'provider_unavailable' ],
        'timeout'               => [ fn ( PayPalFake $paypal ) => $paypal->timeout( 'orders.capture' ), true, 'provider_unavailable' ],
        'order lookup timeout'  => [ fn ( PayPalFake $paypal ) => $paypal->timeout( 'orders.get' ), true, 'provider_unavailable' ],
        'unknown order'         => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.get', 'resource-not-found' ), false, 'paypal_order_not_found' ],
    ] );

    it( 'refuses an Order that is not ready to capture', function ( string $status, bool $retryable, string $code ): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'status' => $status ] ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBe( $retryable )
            ->and( $result->errorCode )->toBe( $code );
        $this->paypal->assertNotSent( 'orders.capture' );
    } )->with( [
        'not approved'          => [ 'CREATED', false, 'payment_not_approved' ],
        'payer action required' => [ 'PAYER_ACTION_REQUIRED', true, 'requires_action' ],
        'voided'                => [ 'VOIDED', false, 'paypal_order_voided' ],
    ] );

    it( 'throws on a currency mismatch', function (): void {
        app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal', 10000, 'EUR' ) );
    } )->throws( PaymentCurrencyMismatchException::class );
} );

describe( 'liability shift policy', function (): void {
    it( 'refuses a card whose 3-D Secure result does not shift liability', function ( string $shift ): void {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( $shift ) );

        $result = app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'liability_shift_rejected' )
            ->and( $result->errorMessage )->toBe( 'Your bank couldn\'t verify this card. Try another card or payment method.' );
        $this->paypal->assertNotSent( 'orders.capture' );
    } )->with( [ 'NO', 'UNKNOWN' ] );

    it( 'refuses a wallet card whose 3-D Secure result does not shift liability', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [
            'payment_source' => [ 'google_pay' => [ 'card' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'authentication_result' => [ 'liability_shift' => 'NO' ] ] ] ],
        ] ) );

        $result = app( PayPalGooglePayGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-google-pay' ) );

        expect( $result->errorCode )->toBe( 'liability_shift_rejected' );
    } );

    it( 'captures a card that shifts liability, or that ran no 3-D Secure', function ( ?string $shift ): void {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( $shift ) );

        expect( app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) )->success )->toBeTrue();
    } )->with( [ 'POSSIBLE', 'YES', null ] );

    it( 'captures regardless of the shift when the policy is off', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.require_liability_shift' => false ] );
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'NO' ) );

        expect( app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) )->success )->toBeTrue();
    } );

    it( 'does not apply to PayPal wallet payments', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'payment_source.paypal.authentication_result' => [ 'liability_shift' => 'NO' ] ] ) );

        expect( app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() )->success )->toBeTrue();
    } );
} );
