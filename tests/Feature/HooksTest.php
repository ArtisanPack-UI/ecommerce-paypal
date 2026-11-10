<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Payments\AuthorizationLifecycle;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\CaptureEventHandler;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\DisputeEventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Sleep;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks as Webhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    Webhooks::boot();
} );

/**
 * Every hook this package fires, with where its payload is proven.
 *
 * @var array<string, string>
 */
const PAYPAL_HOOKS = [
    OrderPayloadBuilder::ORDER_PAYLOAD_HOOK            => 'here, and Payloads/OrderPayloadBuilderTest',
    PayPalGateway::CLIENT_OPTIONS_HOOK                 => 'here, and Gateways/GatewayFamilyTest',
    'ap.ecommerce.paypal.supportedCurrencies'          => 'Support/PayPalCurrenciesTest',
    PayPalGateway::CAPTURED_HOOK                       => 'here',
    CaptureEventHandler::DENIED_HOOK                   => 'Webhooks/WebhookReconciliationTest',
    AuthorizationLifecycle::EXPIRING_HOOK              => 'Payments/AuthorizationLifecycleTest',
    'ap.ecommerce.paypal.disputeCreated'               => 'here, and Webhooks/DisputeEventsTest',
    'ap.ecommerce.paypal.disputeUpdated'               => 'here, and Webhooks/DisputeEventsTest',
    'ap.ecommerce.paypal.disputeResolved'              => 'here, and Webhooks/DisputeEventsTest',
    EcommercePayPalServiceProvider::WEBHOOK_EVENT_HOOK => 'here',
];

describe( 'naming and documentation', function (): void {
    it( 'names every hook with the ap. prefix and camelCase segments', function ( string $hook ): void {
        expect( $hook )->toMatch( '/^ap\.ecommerce\.paypal\.[a-z][A-Za-z]*$/' );
    } )->with( array_keys( PAYPAL_HOOKS ) );

    it( 'documents every hook with a payload table', function ( string $hook ): void {
        $docs = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/extending/hooks-reference.md' );

        expect( $docs )->toContain( '`' . $hook . '`' )
            ->and( $docs )->toContain( '| # | Parameter | Type | Description |' );
    } )->with( array_keys( PAYPAL_HOOKS ) );

    it( 'documents the engine hooks it relies on, with examples', function (): void {
        $docs = (string) file_get_contents( dirname( __DIR__, 2 ) . '/docs/extending/hooks-reference.md' );

        expect( $docs )->toContain( '### `ap.ecommerce.payment.availableGateways`' )
            ->and( $docs )->toContain( '### `ap.ecommerce.payment.clientConfig`' )
            ->and( $docs )->toContain( 'Hide every PayPal method for orders over $10,000' );
    } );
} );

describe( 'ap.ecommerce.paypal.orderPayload', function (): void {
    it( 'passes the payload, cart, and gateway, and lets a host add a field to every Order', function (): void {
        $seen = [];

        addFilter( OrderPayloadBuilder::ORDER_PAYLOAD_HOOK, function ( array $payload, Cart $cart, PayPalGateway $gateway ) use ( &$seen ): array {
            $seen = [ array_keys( $payload ), $cart->id, $gateway->key() ];

            $payload['purchase_units'][0]['description'] = 'From the hook';

            return $payload;
        }, 10, 3 );

        $cart = Webhooks::pendingCart( 'paypal' );
        $cart->forceFill( [ 'payment_reference' => null ] )->save();

        app( PaymentGatewayRegistry::class )->get( 'paypal' )->createPaymentSession( $cart->load( 'items' ) );

        expect( $seen )->toBe( [ [ 'intent', 'purchase_units', 'payment_source' ], $cart->id, 'paypal' ] );

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => 'From the hook' === data_get( $request->data(), 'purchase_units.0.description' ) );
    } );
} );

describe( 'ap.ecommerce.paypal.clientOptions', function (): void {
    it( 'passes the options, cart, session, and gateway', function (): void {
        $seen = [];

        addFilter( PayPalGateway::CLIENT_OPTIONS_HOOK, function ( array $options, Cart $cart, PaymentSession $session, PayPalGateway $gateway ) use ( &$seen ): array {
            $seen = [ $options['order_id'], $cart->id, $session->reference, $gateway->key() ];

            return $options + [ 'style' => [ 'color' => 'black' ] ];
        }, 10, 4 );

        $cart   = Webhooks::pendingCart( 'paypal' );
        $config = app( PaymentGatewayRegistry::class )->get( 'paypal' )->clientConfig( $cart, Orders::session() );

        expect( $seen )->toBe( [ Orders::ORDER_ID, $cart->id, Orders::ORDER_ID, 'paypal' ] )
            ->and( $config['options']['style'] )->toBe( [ 'color' => 'black' ] );
    } );
} );

describe( 'ap.ecommerce.paypal.captured', function (): void {
    beforeEach( function (): void {
        $this->captured = [];

        addAction( PayPalGateway::CAPTURED_HOOK, function ( Order $order, string $captureId, string $status, PayPalOrder $paypalOrder, PayPalGateway $gateway ): void {
            $this->captured[] = [ $order->id, $captureId, $status, $paypalOrder->id(), $gateway->key() ];
        }, 10, 5 );
    } );

    it( 'fires once with the order, capture id, status, PayPal Order, and gateway', function (): void {
        $order   = Orders::create();
        $gateway = app( PaymentGatewayRegistry::class )->get( 'paypal' );

        $gateway->capturePayment( $order, Orders::session() );

        // A retried finalize finds the same capture: no second hook.
        $this->paypal->respond( 'orders.get', 'orders/captured' );
        $gateway->capturePayment( $order->fresh(), Orders::session() );

        expect( $this->captured )->toBe( [ [ $order->id, Orders::CAPTURE_ID, 'COMPLETED', Orders::ORDER_ID, 'paypal' ] ] );
    } );

    it( 'fires for a pending capture', function (): void {
        $this->paypal->respond( 'orders.capture', Orders::body( 'orders/captured', [ 'purchase_units.0.payments.captures.0.status' => 'PENDING' ] ) );

        app( PaymentGatewayRegistry::class )->get( 'paypal' )->capturePayment( $order = Orders::create(), Orders::session() );

        expect( $this->captured )->toBe( [ [ $order->id, Orders::CAPTURE_ID, 'PENDING', Orders::ORDER_ID, 'paypal' ] ] );
    } );

    it( 'still succeeds when a listener throws, since the money has moved', function (): void {
        Illuminate\Support\Facades\Exceptions::fake();

        addAction( PayPalGateway::CAPTURED_HOOK, function (): void {
            throw new RuntimeException( 'Host listener broke.' );
        } );

        $result = app( PaymentGatewayRegistry::class )->get( 'paypal' )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeTrue()
            ->and( $result->gatewayReference )->toBe( Orders::CAPTURE_ID );

        Illuminate\Support\Facades\Exceptions::assertReported( RuntimeException::class );
    } );

    it( 'doesn\'t fire for a declined payment', function (): void {
        $this->paypal->error( 'orders.capture', 'instrument-declined' );

        $result = app( PaymentGatewayRegistry::class )->get( 'paypal' )->capturePayment( Orders::create(), Orders::session() );

        expect( $result->success )->toBeFalse()
            ->and( $this->captured )->toBe( [] );
    } );
} );

describe( 'ap.ecommerce.paypal.disputeCreated / disputeUpdated / disputeResolved', function (): void {
    it( 'fires the hook for the event with the order and dispute summary', function ( string $type, string $hook ): void {
        $order = Orders::create( [ 'payment_gateway_key' => 'paypal', 'payment_status' => 'paid' ], [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID ] );
        $calls = [];

        addAction( $hook, function ( Order $disputed, array $dispute ) use ( &$calls ): void {
            $calls[] = [ $disputed->id, array_keys( $dispute ) ];
        }, 10, 2 );

        Webhooks::post( $this, $type, [ 'dispute_id' => 'PP-D-27803' ] )->assertOk();

        expect( $calls )->toBe( [ [ $order->id, [ 'id', 'reason', 'status', 'stage', 'outcome', 'amount', 'currency', 'respond_by', 'capture_ids', 'url', 'updated_at' ] ] ] );
    } )->with( array_map( null, array_keys( DisputeEventHandler::HOOKS ), array_values( DisputeEventHandler::HOOKS ) ) );
} );

describe( 'ap.ecommerce.paypal.webhookEvent', function (): void {
    it( 'fires for every verified delivery with the type, event, and engine result', function ( string $type, array $resource ): void {
        $calls = [];

        addAction( EcommercePayPalServiceProvider::WEBHOOK_EVENT_HOOK, function ( string $eventType, array $event, WebhookResult $result ) use ( &$calls ): void {
            $calls[] = [ $eventType, $event['id'], $event['resource'], $result->verified ];
        }, 10, 3 );

        Webhooks::post( $this, $type, $resource, 'WH-HOOK-1' )->assertOk();

        expect( $calls )->toBe( [ [ $type, 'WH-HOOK-1', $resource, true ] ] );
    } )->with( [
        'handled'   => [ 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID, 'status' => 'APPROVED' ] ],
        'unhandled' => [ 'BILLING.SUBSCRIPTION.CREATED', [ 'id' => 'I-123' ] ],
    ] );

    it( 'still accepts the delivery when a listener throws', function (): void {
        Illuminate\Support\Facades\Exceptions::fake();

        addAction( EcommercePayPalServiceProvider::WEBHOOK_EVENT_HOOK, function (): void {
            throw new RuntimeException( 'Host listener broke.' );
        } );

        Webhooks::post( $this, 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID ] )->assertOk();

        Illuminate\Support\Facades\Exceptions::assertReported( RuntimeException::class );
    } );

    it( 'doesn\'t fire for a delivery that fails verification', function (): void {
        $calls = 0;

        addAction( EcommercePayPalServiceProvider::WEBHOOK_EVENT_HOOK, function () use ( &$calls ): void {
            $calls++;
        } );

        $this->paypal->respond( 'webhooks.verify_signature', 'webhooks/verify-signature-failure' );

        expect( Webhooks::post( $this, 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID ] )->isSuccessful() )->toBeFalse();

        expect( $calls )->toBe( 0 );
    } );

    it( 'doesn\'t fire twice for a redelivered event', function (): void {
        $calls = 0;

        addAction( EcommercePayPalServiceProvider::WEBHOOK_EVENT_HOOK, function () use ( &$calls ): void {
            $calls++;
        } );

        Webhooks::post( $this, 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID ], 'WH-SAME' )->assertOk();
        Webhooks::post( $this, 'CHECKOUT.ORDER.APPROVED', [ 'id' => Orders::ORDER_ID ], 'WH-SAME' );

        expect( $calls )->toBe( 1 );
    } );
} );

describe( 'engine hooks', function (): void {
    it( 'lets a host hide PayPal for orders over $10,000 with ap.ecommerce.payment.availableGateways', function (): void {
        addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways, Cart $cart ): array {
            if ( (int) $cart->total_amount <= 1_000_000 ) {
                return $gateways;
            }

            return array_filter( $gateways, fn ( $gateway ): bool => ! $gateway instanceof PayPalGateway );
        }, 20, 2 );

        $cart     = Webhooks::pendingCart( 'paypal' );
        $registry = app( PaymentGatewayRegistry::class );

        expect( array_keys( $registry->availableFor( $cart ) ) )->toContain( PayPalWalletGateway::KEY );

        $cart->forceFill( [ 'total_amount' => 1_000_001 ] );

        expect( array_filter( $registry->availableFor( $cart ), fn ( $gateway ): bool => $gateway instanceof PayPalGateway ) )->toBe( [] );
    } );

    it( 'passes PayPal\'s whole client config through ap.ecommerce.payment.clientConfig', function (): void {
        addFilter( 'ap.ecommerce.payment.clientConfig', function ( ?array $config, $gateway ): ?array {
            if ( $gateway instanceof PayPalGateway && null !== $config ) {
                $config['options']['style'] = [ 'shape' => 'pill' ];
            }

            return $config;
        }, 10, 2 );

        $config = ClientPaymentConfig::for( app( PaymentGatewayRegistry::class )->get( 'paypal' ), Webhooks::pendingCart( 'paypal' ), Orders::session() );

        expect( $config['driver'] )->toBe( 'paypal-buttons' )
            ->and( $config['options']['style'] )->toBe( [ 'shape' => 'pill' ] );
    } );
} );
