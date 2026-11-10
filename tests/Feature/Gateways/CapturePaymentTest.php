<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks as Webhooks;

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

    it( 'keeps a capture a webhook already settled when the pending capture response is recorded after it', function ( string $settled ): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [
            'purchase_units.0.payments.captures.0.status'         => 'PENDING',
            'purchase_units.0.payments.captures.0.status_details' => [ 'reason' => 'ECHECK' ],
        ] ) );

        // The webhook settled the capture while the capture call was in flight.
        $order = Orders::create( [], [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID, 'capture_status' => $settled ] );

        expect( app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() )->success )->toBeTrue()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_status' ) )->toBe( $settled )
            ->and( PayPalOrderMeta::get( $order->fresh(), 'pending_reason' ) )->toBeNull()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_id' ) )->toBe( Orders::CAPTURE_ID );
    } )->with( [ 'COMPLETED', 'DENIED' ] );

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
        $this->paypal = PayPalFake::fake();
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE', 'AUTHORIZE' ) );

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
        $this->paypal = PayPalFake::fake();
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE', 'AUTHORIZE' ) );

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

describe( 'instrument declined restart', function (): void {
    it( 'records the decline and asks the buttons to restart on the same Order', function (): void {
        $order = Orders::create();
        $this->paypal->error( 'orders.capture', 'instrument-declined' );

        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );
        $config = app( PayPalWalletGateway::class )->clientConfig( new Cart(), Orders::session() );

        expect( $result->errorCode )->toBe( 'instrument_declined' )
            ->and( $result->retryable )->toBeTrue()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'instrument_declines' ) )->toBe( 1 )
            ->and( $config['options']['restart'] )->toBeTrue()
            ->and( $config['client_secret'] )->toBe( Orders::ORDER_ID );
    } );

    it( 'captures the retry with a new request id', function (): void {
        $order = Orders::create();
        $this->paypal->error( 'orders.capture', 'instrument-declined' );

        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );
        $result = app( PayPalWalletGateway::class )->capturePayment( $order->fresh(), Orders::session() );

        $ids = array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'orders.capture' ) );

        expect( $result->success )->toBeTrue()
            ->and( $ids )->toBe( [ 'capture-' . Orders::ORDER_ID, 'capture-' . Orders::ORDER_ID . '-2' ] );

        $config = app( PayPalWalletGateway::class )->clientConfig( new Cart(), Orders::session() );

        expect( $config['options']['restart'] )->toBeFalse();
    } );
} );

describe( 'instrument declined restart, intent authorize', function (): void {
    beforeEach( function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'intent' => 'AUTHORIZE' ] ) );
    } );

    it( 'records the decline and asks the buttons to restart on the same Order', function (): void {
        $order = Orders::create();
        $this->paypal->error( 'orders.authorize', 'instrument-declined' );

        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );
        $config = app( PayPalWalletGateway::class )->clientConfig( new Cart(), Orders::session() );

        expect( $result->errorCode )->toBe( 'instrument_declined' )
            ->and( $result->retryable )->toBeTrue()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'instrument_declines' ) )->toBe( 1 )
            ->and( $config['options']['restart'] )->toBeTrue();
    } );

    it( 'authorizes the retry with a new request id', function (): void {
        $order = Orders::create();
        $this->paypal->error( 'orders.authorize', 'instrument-declined' );

        app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'intent' => 'AUTHORIZE' ] ) );
        $result = app( PayPalWalletGateway::class )->capturePayment( $order->fresh(), Orders::session() );

        $ids    = array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'orders.authorize' ) );
        $config = app( PayPalWalletGateway::class )->clientConfig( new Cart(), Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $ids )->toBe( [ 'authorize-' . Orders::ORDER_ID, 'authorize-' . Orders::ORDER_ID . '-2' ] )
            ->and( $config['options']['restart'] )->toBeFalse();
    } );
} );

describe( 'cart check', function (): void {
    it( 'refuses a captured PayPal Order created for another cart, without firing the captured hook', function (): void {
        $fired = false;
        addAction( PayPalGateway::CAPTURED_HOOK, function () use ( &$fired ): void {
            $fired = true;
        } );

        $order = Orders::create();
        $cart  = Cart::factory()->create( [ 'completed_order_id' => $order->id ] );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [ 'purchase_units.0.custom_id' => (string) ( $cart->id + 1 ) ] ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'paypal_order_mismatch' )
            ->and( $fired )->toBeFalse()
            ->and( PayPalOrderMeta::get( $order->fresh(), 'capture_id' ) )->toBeNull();
    } );

    it( 'captures a PayPal Order created for the order\'s cart', function (): void {
        $order = Orders::create();
        $cart  = Cart::factory()->create( [ 'completed_order_id' => $order->id ] );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'purchase_units.0.custom_id' => (string) $cart->id ] ) );

        expect( app( PayPalWalletGateway::class )->capturePayment( $order, Orders::session() )->success )->toBeTrue();
    } );
} );

describe( 'liability shift policy under SCA_ALWAYS', function (): void {
    beforeEach( function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.three_d_secure' => 'SCA_ALWAYS' ] );
    } );

    it( 'refuses a card that ran no 3-D Secure', function (): void {
        $this->paypal->respond( 'orders.get', Orders::approvedCard( null ) );

        $result = app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) );

        expect( $result->errorCode )->toBe( 'liability_shift_rejected' );
        $this->paypal->assertNotSent( 'orders.capture' );
    } );

    it( 'captures a saved card that ran no 3-D Secure', function ( array $card ): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'payment_source' => [ 'card' => [ 'brand' => 'VISA', 'last_digits' => '1111', ...$card ] ], 'payer' => null ] ) );

        expect( app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) )->success )->toBeTrue();
    } )->with( [
        'vault id'          => [ [ 'vault_id' => 'VAULT-1' ] ],
        'stored credential' => [ [ 'stored_credential' => [ 'payment_initiator' => 'CUSTOMER' ] ] ],
    ] );

    it( 'does not hold wallets to SCA_ALWAYS', function (): void {
        $this->paypal->respond( 'orders.get', Orders::approvedWith( 'apple_pay' ) );

        expect( app( PayPalApplePayGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-apple-pay' ) )->success )->toBeTrue();
    } );

    it( 'leaves the policy to the shift itself under SCA_WHEN_REQUIRED', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.three_d_secure' => 'SCA_WHEN_REQUIRED' ] );
        $this->paypal->respond( 'orders.get', Orders::approvedCard( null ) );

        expect( app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) )->success )->toBeTrue();
    } );
} );

describe( 'liability shift policy on an Order the browser captured', function (): void {
    it( 'refunds and refuses a captured card Order whose 3-D Secure result does not shift liability', function (): void {
        $fired = false;
        addAction( PayPalGateway::CAPTURED_HOOK, function () use ( &$fired ): void {
            $fired = true;
        } );

        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [ 'payment_source' => [ 'card' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'authentication_result' => [ 'liability_shift' => 'NO' ] ] ] ] ) );

        $order  = Orders::create( [ 'payment_gateway_key' => 'paypal-card' ] );
        $result = app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'liability_shift_rejected' )
            ->and( $fired )->toBeFalse();

        $this->paypal->assertSent( 'payments.captures.refund', fn ( Request $request ): bool => str_contains( $request->url(), '/captures/' . Orders::CAPTURE_ID . '/refund' )
            && 'liability-refund-' . Orders::CAPTURE_ID === $request->header( 'PayPal-Request-Id' )[0]
            && [ 'currency_code' => 'USD', 'value' => '100.00' ] === $request['amount'] );

        $entry = OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.liability_shift_refunded' )->sole();

        expect( $entry->payload )->toMatchArray( [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID, 'liability_shift' => 'NO' ] );
    } );

    it( 'refunds and records the refund once when the engine retries', function (): void {
        $body = Orders::body( 'orders/captured', [ 'payment_source' => [ 'card' => [ 'authentication_result' => [ 'liability_shift' => 'NO' ] ] ] ] );
        $this->paypal->respond( 'orders.get', $body )->respond( 'orders.get', $body );

        $order = Orders::create();
        app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );
        app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );

        $this->paypal->assertSentCount( 'payments.captures.refund', 1 );

        expect( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.liability_shift_refunded' )->count() )->toBe( 1 );
    } );

    it( 'does not refund a capture that is already refunded', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [
            'payment_source'                              => [ 'card' => [ 'authentication_result' => [ 'liability_shift' => 'NO' ] ] ],
            'purchase_units.0.payments.captures.0.status' => 'REFUNDED',
        ] ) );

        $result = app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) );

        expect( $result->errorCode )->toBe( 'liability_shift_rejected' );
        $this->paypal->assertNotSent( 'payments.captures.refund' );
    } );

    it( 'records a refund PayPal refuses', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [ 'payment_source' => [ 'card' => [ 'authentication_result' => [ 'liability_shift' => 'UNKNOWN' ] ] ] ] ) );
        $this->paypal->respond( 'payments.captures.refund', Orders::issue( 'TRANSACTION_REFUSED' ), 422 );

        $order  = Orders::create();
        $result = app( PayPalCardGateway::class )->capturePayment( $order, Orders::session( 'paypal-card' ) );

        expect( $result->errorCode )->toBe( 'liability_shift_rejected' )
            ->and( OrderTimelineEntry::query()->where( 'order_id', $order->id )->where( 'event_type', 'paypal.liability_shift_refund_failed' )->sole()->payload['error'] )->toBe( 'TRANSACTION_REFUSED' );
    } );

    it( 'still returns the capture of a card Order that shifted liability', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/captured', [ 'payment_source' => [ 'card' => [ 'authentication_result' => [ 'liability_shift' => 'POSSIBLE' ] ] ] ] ) );

        expect( app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) )->success )->toBeTrue();
        $this->paypal->assertNotSent( 'payments.captures.refund' );
    } );
} );

describe( 'shipping address', function (): void {
    /**
     * An engine order shipping to `$address1`.
     */
    $order = static fn ( string $address1 = '2211 N First Street' ): Order => Orders::create( [
        'shipping_address' => [
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'address1'     => $address1,
            'city'         => 'San Jose',
            'region_code'  => 'CA',
            'postal_code'  => '95131',
            'country_code' => 'US',
        ],
    ] );

    it( 'updates the PayPal Order to the order\'s address before capturing', function () use ( $order ): void {
        $result = app( PayPalWalletGateway::class )->capturePayment( $order( '1 New Street' ), Orders::session() );

        expect( $result->success )->toBeTrue();

        $this->paypal->assertSent( 'orders.patch', function ( Request $request ): bool {
            $operations = $request->data();

            return str_starts_with( $request->header( 'PayPal-Request-Id' )[0], 'patch-' . Orders::ORDER_ID . '-' )
                && 'replace' === $operations[0]['op']
                && "/purchase_units/@reference_id=='default'/shipping/address" === $operations[0]['path']
                && '1 New Street' === $operations[0]['value']['address_line_1']
                && "/purchase_units/@reference_id=='default'/shipping/name" === $operations[1]['path']
                && [ 'full_name' => 'John Doe' ] === $operations[1]['value'];
        } );

        $requests = $this->paypal->sent( 'orders.patch' );
        $captures = $this->paypal->sent( 'orders.capture' );

        expect( $requests )->toHaveCount( 1 )
            ->and( $captures )->toHaveCount( 1 );
    } );

    it( 'leaves an Order that already has the order\'s address alone', function () use ( $order ): void {
        expect( app( PayPalWalletGateway::class )->capturePayment( $order(), Orders::session() )->success )->toBeTrue();

        $this->paypal->assertNotSent( 'orders.patch' );
    } );

    it( 'leaves an Order whose buyer picked the address in PayPal alone', function () use ( $order ): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'payment_source.paypal.experience_context' => [ 'shipping_preference' => 'GET_FROM_FILE' ] ] ) );

        expect( app( PayPalWalletGateway::class )->capturePayment( $order( '1 New Street' ), Orders::session() )->success )->toBeTrue();

        $this->paypal->assertNotSent( 'orders.patch' );
    } );

    it( 'still captures when PayPal refuses the update, and records why', function () use ( $order ): void {
        $this->paypal->respond( 'orders.patch', Orders::issue( 'UNPROCESSABLE_ENTITY' ), 422 );

        $engineOrder = $order( '1 New Street' );
        $result      = app( PayPalWalletGateway::class )->capturePayment( $engineOrder, Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( OrderTimelineEntry::query()->where( 'order_id', $engineOrder->id )->where( 'event_type', 'paypal.shipping_patch_failed' )->sole()->payload )
            ->toMatchArray( [ 'order_id' => Orders::ORDER_ID, 'error' => 'UNPROCESSABLE_ENTITY' ] );

        $this->paypal->assertSentCount( 'orders.capture', 1 );
    } );

    it( 'retries later when PayPal can\'t be reached for the update', function () use ( $order ): void {
        $this->paypal->timeout( 'orders.patch' );

        $result = app( PayPalWalletGateway::class )->capturePayment( $order( '1 New Street' ), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeTrue();
        $this->paypal->assertNotSent( 'orders.capture' );
    } );
} );

describe( 'payment source check', function (): void {
    it( 'refuses a card-paid Order on the PayPal gateway while card fields are on', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.enabled' => true ] );
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE' ) );

        $result = app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $result->retryable )->toBeFalse()
            ->and( $result->errorCode )->toBe( 'payment_source_mismatch' );
        $this->paypal->assertNotSent( 'orders.capture' );
    } );

    it( 'captures PayPal\'s own guest-card button while card fields are off', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.card.enabled' => false ] );
        $this->paypal->respond( 'orders.get', Orders::approvedCard( 'POSSIBLE' ) );

        expect( app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() )->success )->toBeTrue();
    } );

    it( 'refuses an Apple Pay Order on the card gateway', function (): void {
        $this->paypal->respond( 'orders.get', Orders::approvedWith( 'apple_pay' ) );

        $result = app( PayPalCardGateway::class )->capturePayment( Orders::create(), Orders::session( 'paypal-card' ) );

        expect( $result->errorCode )->toBe( 'payment_source_mismatch' );
        $this->paypal->assertNotSent( 'orders.capture' );
    } );

    it( 'captures a Venmo Order on the PayPal gateway', function (): void {
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'payment_source' => [ 'venmo' => [ 'email_address' => 'buyer@example.com' ] ] ] ) );

        expect( app( PayPalWalletGateway::class )->capturePayment( Orders::create(), Orders::session() )->success )->toBeTrue();
    } );
} );

describe( 'whole-unit currencies', function (): void {
    it( 'finalizes a HUF checkout whose total is whole forints, end to end', function (): void {
        Webhooks::boot();
        config( [ 'artisanpack.ecommerce.base_currency' => 'HUF' ] );

        $cart = Webhooks::pendingCart( 'paypal', 'HUF', 1_234_000, 50_000 );
        $cart->forceFill( [ 'payment_reference' => null ] )->save();

        $total = (int) $cart->total_amount;
        $value = (string) intdiv( $total, 100 );
        $money = [ 'currency_code' => 'HUF', 'value' => $value ];

        expect( $total % 100 )->toBe( 0 );

        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'purchase_units.0.amount' => $money, 'purchase_units.0.custom_id' => (string) $cart->id ] ) );
        $this->paypal->respond( 'orders.get', Orders::body( 'orders/approved', [ 'purchase_units.0.amount' => $money, 'purchase_units.0.custom_id' => (string) $cart->id ] ) );
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [
            'purchase_units.0.amount'                      => $money,
            'purchase_units.0.custom_id'                   => (string) $cart->id,
            'purchase_units.0.payments.captures.0.amount'  => $money,
        ] ) );
        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'purchase_units.0.amount' => $money ] ) );

        $checkout = app( CheckoutService::class );
        $session  = $checkout->createPaymentSession( $cart );

        expect( (int) $session->amount->getAmount() )->toBe( $total );
        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => $value === $request['purchase_units'][0]['amount']['value'] );

        $result = $checkout->finalize( $cart->refresh(), $session->reference );

        expect( $result->isComplete() )->toBeTrue()
            ->and( (int) $result->order->total_amount )->toBe( $total )
            ->and( PayPalOrderMeta::get( $result->order->fresh(), 'capture_id' ) )->toBe( Orders::CAPTURE_ID );
    } );
} );
