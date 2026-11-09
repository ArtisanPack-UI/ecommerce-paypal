<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderNote;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\DisputeEventHandler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks as Webhooks;

uses( RefreshDatabase::class );

const PAYPAL_DISPUTE_ID = 'PP-D-27803';

beforeEach( function (): void {
    Sleep::fake();

    $this->travelTo( CarbonImmutable::parse( '2026-11-12T12:00:00Z' ) );
    $this->paypal = PayPalFake::fake();

    Webhooks::boot();

    $this->order = Orders::create(
        [ 'payment_gateway_key' => 'paypal-card', 'payment_status' => 'paid' ],
        [ 'order_id' => Orders::ORDER_ID, 'capture_id' => Orders::CAPTURE_ID ],
    );

    $this->hooks = [];

    foreach ( DisputeEventHandler::HOOKS as $hook ) {
        addAction( $hook, function ( Order $order, array $dispute ) use ( $hook ): void {
            $this->hooks[] = [ $hook, $order->id, $dispute['id'] ];
        } );
    }
} );

/**
 * Posts a dispute event whose resource only names the dispute.
 *
 * @param  mixed   $test     Test case.
 * @param  string  $type     Event type.
 * @param  string  $eventId  Event id.
 *
 * @return Illuminate\Testing\TestResponse
 */
function postPayPalDispute( mixed $test, string $type, string $eventId = 'WH-DISPUTE-1' ): Illuminate\Testing\TestResponse
{
    return Webhooks::post( $test, $type, [ 'dispute_id' => PAYPAL_DISPUTE_ID, 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED' ], $eventId );
}

it( 'adds a translated system note with the reason, amount, respond-by date, and Resolution Center link', function (): void {
    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED' )->assertOk();

    $note = OrderNote::query()->where( 'order_id', $this->order->id )->sole();

    expect( $note->author_user_id )->toBeNull()
        ->and( $note->is_customer_visible )->toBeFalse()
        ->and( $note->body )->toContain( 'PayPal dispute PP-D-27803 opened: item not received, 100.00 USD.' )
        ->and( $note->body )->toContain( 'Respond by Sunday, November 22, 2026 10:00 AM UTC' )
        ->and( $note->body )->toContain( 'https://www.sandbox.paypal.com/resolutioncenter/view/PP-D-27803' );
} );

it( 'records a timeline entry, a dispute summary, and fires the hook for each event', function ( string $type, string $hook, string $timeline ): void {
    postPayPalDispute( $this, $type )->assertOk();

    $entry = OrderTimelineEntry::query()->where( 'order_id', $this->order->id )->where( 'event_type', $timeline )->sole();

    expect( $entry->payload )->toMatchArray( [ 'id' => PAYPAL_DISPUTE_ID, 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED', 'amount' => '100.00', 'currency' => 'USD' ] )
        ->and( PayPalOrderMeta::get( $this->order->fresh(), 'disputes.' . PAYPAL_DISPUTE_ID ) )->toMatchArray( [
            'id'          => PAYPAL_DISPUTE_ID,
            'status'      => 'WAITING_FOR_SELLER_RESPONSE',
            'stage'       => 'INQUIRY',
            'capture_ids' => [ Orders::CAPTURE_ID ],
            'respond_by'  => '2026-11-22T10:00:00+00:00',
        ] )
        ->and( $this->hooks )->toBe( [ [ $hook, $this->order->id, PAYPAL_DISPUTE_ID ] ] );
} )->with( [
    'created'  => [ 'CUSTOMER.DISPUTE.CREATED', 'ap.ecommerce.paypal.disputeCreated', 'paypal.dispute_created' ],
    'updated'  => [ 'CUSTOMER.DISPUTE.UPDATED', 'ap.ecommerce.paypal.disputeUpdated', 'paypal.dispute_updated' ],
    'resolved' => [ 'CUSTOMER.DISPUTE.RESOLVED', 'ap.ecommerce.paypal.disputeResolved', 'paypal.dispute_resolved' ],
] );

it( 'notes an update with the new status', function (): void {
    $this->paypal->respond( 'disputes.get', Orders::body( 'disputes/dispute', [ 'status' => 'UNDER_REVIEW', 'dispute_life_cycle_stage' => 'CHARGEBACK' ] ) );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.UPDATED' )->assertOk();

    expect( OrderNote::query()->sole()->body )->toContain( 'PayPal dispute PP-D-27803 updated: under review (item not received, 100.00 USD).' )
        ->and( PayPalOrderMeta::get( $this->order->fresh(), 'disputes.' . PAYPAL_DISPUTE_ID . '.stage' ) )->toBe( 'CHARGEBACK' );
} );

it( 'notes the outcome of a resolved dispute', function (): void {
    $this->paypal->respond( 'disputes.get', Orders::body( 'disputes/dispute', [ 'status' => 'RESOLVED', 'seller_response_due_date' => null, 'dispute_outcome' => [ 'outcome_code' => 'RESOLVED_SELLER_FAVOUR' ] ] ) );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.RESOLVED' )->assertOk();

    expect( OrderNote::query()->sole()->body )->toBe( 'PayPal dispute PP-D-27803 was resolved. Outcome: in your favor. Resolution Center: https://www.sandbox.paypal.com/resolutioncenter/view/PP-D-27803' )
        ->and( PayPalOrderMeta::get( $this->order->fresh(), 'disputes.' . PAYPAL_DISPUTE_ID . '.outcome' ) )->toBe( 'RESOLVED_SELLER_FAVOUR' );
} );

it( 'keeps every dispute an order has had', function (): void {
    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED', 'WH-1' )->assertOk();
    $this->paypal->respond( 'disputes.get', Orders::body( 'disputes/dispute', [ 'dispute_id' => 'PP-D-99999' ] ) );

    Webhooks::post( $this, 'CUSTOMER.DISPUTE.CREATED', [ 'dispute_id' => 'PP-D-99999' ], 'WH-2' )->assertOk();

    expect( array_keys( PayPalOrderMeta::get( $this->order->fresh(), 'disputes' ) ) )->toBe( [ PAYPAL_DISPUTE_ID, 'PP-D-99999' ] );
} );

it( 'does not change the order status', function (): void {
    $before = $this->order->fresh()->only( [ 'status', 'payment_status' ] );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED' )->assertOk();

    expect( $this->order->fresh()->only( [ 'status', 'payment_status' ] ) )->toBe( $before );
} );

it( 'ignores disputes about captures no order recorded', function (): void {
    $this->paypal->respond( 'disputes.get', Orders::body( 'disputes/dispute', [ 'disputed_transactions.0.seller_transaction_id' => 'UNKNOWN-CAPTURE' ] ) );
    $this->paypal->error( 'payments.captures.get', 'resource-not-found' );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED' )->assertOk();

    expect( OrderNote::query()->count() )->toBe( 0 )
        ->and( OrderTimelineEntry::query()->count() )->toBe( 0 )
        ->and( $this->hooks )->toBe( [] );
} );

it( 'trusts the dispute PayPal returns, not the webhook body', function (): void {
    $this->paypal->respond( 'disputes.get', Orders::body( 'disputes/dispute', [ 'reason' => 'INCORRECT_AMOUNT' ] ) );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED' )->assertOk();

    expect( OrderNote::query()->sole()->body )->toContain( 'incorrect amount' );
    $this->paypal->assertSent( 'disputes.get' );
} );

it( 'skips a dispute PayPal no longer has', function (): void {
    $this->paypal->error( 'disputes.get', 'resource-not-found' );

    postPayPalDispute( $this, 'CUSTOMER.DISPUTE.CREATED' )->assertOk();

    expect( OrderNote::query()->count() )->toBe( 0 );
} );

it( 'labels every documented reason and outcome', function (): void {
    foreach ( [ 'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED', 'UNAUTHORISED', 'CREDIT_NOT_PROCESSED', 'DUPLICATE_TRANSACTION', 'PAYMENT_BY_OTHER_MEANS', 'CANCELED_RECURRING_BILLING', 'PROBLEM_WITH_REMITTANCE' ] as $reason ) {
        expect( DisputeEventHandler::reasonLabel( $reason ) )->not->toBe( __( 'other' ) );
    }

    foreach ( [ 'RESOLVED_BUYER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'CANCELED_BY_BUYER', 'ACCEPTED', 'DENIED' ] as $outcome ) {
        expect( DisputeEventHandler::outcomeLabel( $outcome ) )->not->toBe( __( 'closed' ) );
    }

    expect( DisputeEventHandler::reasonLabel( 'SOMETHING_NEW' ) )->toBe( 'other' );
} );

it( 'adds one note when PayPal redelivers a dispute event the order already processed', function (): void {
    $event = [ 'id' => 'WH-DISPUTE-AGAIN', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => [ 'dispute_id' => PAYPAL_DISPUTE_ID ] ];

    app( ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor::class )->process( $event );
    app( ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor::class )->process( $event );

    expect( OrderNote::query()->count() )->toBe( 1 )
        ->and( $this->hooks )->toHaveCount( 1 );
} );

it( 'writes the respond-by date in the app locale and timezone', function (): void {
    app()->setLocale( 'fr' );
    config( [ 'app.timezone' => 'Europe/Paris' ] );

    expect( DisputeEventHandler::date( CarbonImmutable::parse( '2026-11-22T10:00:00Z' ) ) )->toBe( 'dimanche 22 novembre 2026 11:00 CET' )
        ->and( DisputeEventHandler::date( null ) )->toBeNull();
} );

it( 'processes a dispute event again when its first attempt failed', function (): void {
    $event    = [ 'id' => 'WH-DISPUTE-RETRY', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => [ 'dispute_id' => PAYPAL_DISPUTE_ID ] ];
    $attempts = 0;

    addAction( 'ap.ecommerce.paypal.disputeCreated', function () use ( &$attempts ): void {
        if ( 1 === ++$attempts ) {
            throw new RuntimeException( 'listener failed' );
        }
    } );

    expect( fn () => app( ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor::class )->process( $event ) )->toThrow( RuntimeException::class );

    app( ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor::class )->process( $event );

    expect( $attempts )->toBe( 2 )
        ->and( PayPalOrderMeta::get( $this->order->fresh(), 'webhook_events' ) )->toBe( [ 'WH-DISPUTE-RETRY' ] );
} );
