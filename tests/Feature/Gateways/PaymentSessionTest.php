<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Tests\Support\CartBuilder;

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'     => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret' => 'client-secret',
        'artisanpack.ecommerce-paypal.http.retries'  => 0,
    ] );

    Sleep::fake();

    $this->paypal = PayPalFake::fake();
} );

describe( 'createPaymentSession', function (): void {
    it( 'creates the PayPal Order with the engine idempotency key and returns a normalized session', function (): void {
        $cart    = CartBuilder::make( [ [ 'Shirt', 10000, 1 ] ] );
        $session = app( PayPalWalletGateway::class )->createPaymentSession( $cart, [ 'idempotency_key' => 'idem-123' ] );

        expect( $session->gatewayKey )->toBe( 'paypal' )
            ->and( $session->reference )->toBe( '5O190127TN364715T' )
            ->and( (int) $session->amount->getAmount() )->toBe( 10000 )
            ->and( $session->amount->getCurrency()->getCode() )->toBe( 'USD' )
            ->and( $session->status )->toBe( PaymentSession::STATUS_REQUIRES_ACTION )
            ->and( $session->redirectUrl )->toBe( 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T' )
            ->and( $session->clientSecret )->toBeNull();

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => 'idem-123' === $request->header( 'PayPal-Request-Id' )[0]
            && 'return=representation' === $request->header( 'Prefer' )[0]
            && 'CAPTURE' === $request['intent']
            && '42' === $request['purchase_units'][0]['custom_id']
            && isset( $request['payment_source']['paypal']['experience_context'] ) );
    } );

    it( 'creates card Orders with the card payment source', function (): void {
        app( PayPalCardGateway::class )->createPaymentSession( CartBuilder::make( [ [ 'Shirt', 2500, 2 ] ] ), [
            'idempotency_key' => 'k',
            'return_url'      => 'https://shop.test/checkout/return',
        ] );

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => isset( $request['payment_source']['card']['experience_context']['return_url'] ) );
    } );

    it( 'falls back to the cart total when PayPal answers without an amount', function (): void {
        $this->paypal->respond( 'orders.create', [ 'id' => 'MINIMAL', 'status' => 'CREATED' ] );

        $session = app( PayPalWalletGateway::class )->createPaymentSession( CartBuilder::make( [ [ 'Shirt', 2500, 2 ] ] ), [ 'idempotency_key' => 'k' ] );

        expect( $session->reference )->toBe( 'MINIMAL' )
            ->and( (int) $session->amount->getAmount() )->toBe( 5000 )
            ->and( $session->status )->toBe( PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD );
    } );

    it( 'keeps a request id on the cart when the engine passes no key, so a retry returns the same Order', function (): void {
        $gateway = app( PayPalWalletGateway::class );
        $cart    = CartBuilder::make( [ [ 'Shirt', 10000, 1 ] ] );

        $gateway->createPaymentSession( $cart );
        $first = $cart->meta['paypal']['request_id'];

        $gateway->createPaymentSession( $cart );

        $ids = array_map( fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'orders.create' ) );

        expect( $first )->toBeString()->toMatch( '/^[0-9a-f-]{36}$/' )
            ->and( $ids )->toBe( [ $first, $first ] )
            ->and( $cart->meta['paypal']['request_order_id'] )->toBe( '5O190127TN364715T' );
    } );

    it( 'mints a new request id when the cart total, currency, or gateway changes', function (): void {
        $cart = CartBuilder::make( [ [ 'Shirt', 10000, 1 ] ] );

        app( PayPalWalletGateway::class )->createPaymentSession( $cart );
        $first = $cart->meta['paypal']['request_id'];

        $cart->total_amount = 12000;
        app( PayPalWalletGateway::class )->createPaymentSession( $cart );
        $second = $cart->meta['paypal']['request_id'];

        app( PayPalCardGateway::class )->createPaymentSession( $cart );
        $third = $cart->meta['paypal']['request_id'];

        expect( [ $first, $second, $third ] )->toHaveCount( 3 )
            ->and( array_unique( [ $first, $second, $third ] ) )->toHaveCount( 3 );
    } );

    it( 'mints a new request id once the Order it produced is the cart\'s payment reference', function (): void {
        $gateway = app( PayPalWalletGateway::class );
        $cart    = CartBuilder::make( [ [ 'Shirt', 10000, 1 ] ] );

        $gateway->createPaymentSession( $cart );
        $first = $cart->meta['paypal']['request_id'];

        // The engine stored the Order, then judged it unusable and asked again.
        $cart->payment_reference = '5O190127TN364715T';
        $gateway->createPaymentSession( $cart );

        expect( $cart->meta['paypal']['request_id'] )->not->toBe( $first );
    } );

    it( 'throws when PayPal refuses the Order', function (): void {
        $this->paypal->error( 'orders.create', 'invalid-request' );

        app( PayPalWalletGateway::class )->createPaymentSession( CartBuilder::make( [ [ 'Shirt', 10000, 1 ] ] ), [ 'idempotency_key' => 'k' ] );
    } )->throws( PayPalApiException::class );
} );

describe( 'retrievePaymentSession', function (): void {
    it( 'maps each PayPal Order state to a session status (spec §7.2)', function ( array $order, ?string $expected ): void {
        $this->paypal->respond( 'orders.get', $order );

        $session = app( PayPalWalletGateway::class )->retrievePaymentSession( $order['id'] );

        expect( $session->status )->toBe( $expected )
            ->and( $session->reference )->toBe( $order['id'] )
            ->and( (int) $session->amount->getAmount() )->toBe( 10000 );
    } )->with( [
        'created'                    => [ fn () => paypalOrderWithStatus( 'CREATED' ), PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD ],
        'saved'                      => [ fn () => paypalOrderWithStatus( 'SAVED' ), PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD ],
        'payer action required'      => [ fn () => PayPalFake::fixture( 'orders/created' ), PaymentSession::STATUS_REQUIRES_ACTION ],
        'approved'                   => [ fn () => PayPalFake::fixture( 'orders/approved' ), PaymentSession::STATUS_AUTHORIZED ],
        'completed, capture done'    => [ fn () => PayPalFake::fixture( 'orders/captured' ), PaymentSession::STATUS_SUCCEEDED ],
        'completed, capture pending' => [ fn () => paypalCapturedOrder( 'PENDING' ), PaymentSession::STATUS_PROCESSING ],
        'completed, authorized'      => [ fn () => PayPalFake::fixture( 'orders/authorized' ), PaymentSession::STATUS_AUTHORIZED ],
        'voided'                     => [ fn () => paypalOrderWithStatus( 'VOIDED' ), PaymentSession::STATUS_CANCELED ],
    ] );

    it( 'only reads: it never creates, authorizes, or captures', function (): void {
        app( PayPalWalletGateway::class )->retrievePaymentSession( '5O190127TN364715T' );

        $this->paypal->assertSentCount( 'orders.get', 1 );
        $this->paypal->assertNotSent( 'orders.create' );
        $this->paypal->assertNotSent( 'orders.authorize' );
        $this->paypal->assertNotSent( 'orders.capture' );
        $this->paypal->assertNotSent( 'payments.authorizations.capture' );
    } );

    it( 'puts the 3-D Secure liability shift and payment details in the session metadata', function (): void {
        $this->paypal->respond( 'orders.get', 'orders/authorized' );

        $session = app( PayPalCardGateway::class )->retrievePaymentSession( '5O190127TN364715T' );

        expect( $session->gatewayKey )->toBe( 'paypal-card' )
            ->and( $session->metadata )->toBe( [
                'paypal_status'   => 'COMPLETED',
                'intent'          => 'authorize',
                'payment_source'  => 'card',
                'liability_shift' => 'POSSIBLE',
                'authorization'   => '0VF52814937998046',
            ] );
    } );

    it( 'reads the liability shift of a wallet card', function (): void {
        $order                   = PayPalFake::fixture( 'orders/approved' );
        $order['payment_source'] = [ 'google_pay' => [ 'card' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'authentication_result' => [ 'liability_shift' => 'NO' ] ] ] ];
        $this->paypal->respond( 'orders.get', $order );

        expect( app( PayPalApplePayGateway::class )->retrievePaymentSession( $order['id'] )->metadata['liability_shift'] )->toBe( 'NO' );
    } );

    it( 'carries the capture id as the charge once captured', function (): void {
        $this->paypal->respond( 'orders.get', 'orders/captured' );

        $session = app( PayPalWalletGateway::class )->retrievePaymentSession( '5O190127TN364715T' );

        expect( $session->isCaptured() )->toBeTrue()
            ->and( $session->metadata['charge'] )->toBe( '3C679366HH908993F' );
    } );

    it( 'throws for an unknown Order', function (): void {
        $this->paypal->error( 'orders.get', 'resource-not-found' );

        expect( fn () => app( PayPalWalletGateway::class )->retrievePaymentSession( 'NOPE' ) )
            ->toThrow( fn ( PayPalApiException $exception ) => expect( $exception->status )->toBe( 404 )->and( $exception->isRetryable() )->toBeFalse() );
    } );

    it( 'throws a retryable exception when PayPal fails or times out', function ( Closure $fail ): void {
        $fail( $this->paypal );

        expect( fn () => app( PayPalWalletGateway::class )->retrievePaymentSession( '5O190127TN364715T' ) )
            ->toThrow( fn ( PayPalApiException $exception ) => expect( $exception->isRetryable() )->toBeTrue() );
    } )->with( [
        '5xx'     => [ fn ( PayPalFake $paypal ) => $paypal->error( 'orders.get', 'internal-server-error' ) ],
        'timeout' => [ fn ( PayPalFake $paypal ) => $paypal->timeout( 'orders.get' ) ],
    ] );
} );

/**
 * The approved Order fixture with another status.
 *
 * @param  string  $status  Order status.
 *
 * @return array<string, mixed>
 */
function paypalOrderWithStatus( string $status ): array
{
    return array_replace( PayPalFake::fixture( 'orders/approved' ), [ 'status' => $status ] );
}

/**
 * The captured Order fixture with its capture in `$status`.
 *
 * @param  string  $status  Capture status.
 *
 * @return array<string, mixed>
 */
function paypalCapturedOrder( string $status ): array
{
    $order = PayPalFake::fixture( 'orders/captured' );

    $order['purchase_units'][0]['payments']['captures'][0]['status'] = $status;

    if ( 'PENDING' === $status ) {
        $order['purchase_units'][0]['payments']['captures'][0]['status_details'] = [ 'reason' => 'PENDING_REVIEW' ];
    }

    return $order;
}
