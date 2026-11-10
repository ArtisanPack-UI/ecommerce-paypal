<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommerceAdminLivewire\Registries\OrderPanelRegistry;
use ArtisanPackUI\EcommercePayPal\Livewire\Admin\PayPalOrderPanel;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    PayPalFake::fake();

    PayPalWebhooks::boot();

    $this->travelTo( CarbonImmutable::parse( '2026-11-12T12:00:00Z' ) );
} );

/**
 * Signs in a staff member who may (or may not) view orders.
 *
 * @param  bool  $canView  Whether `order.view` is granted.
 *
 * @return void
 */
function panelStaff( bool $canView = true ): void
{
    addFilter( 'ap.ecommerce.abilities.order.view', static fn (): bool => $canView );

    test()->actingAs( new GenericUser( [ 'id' => 500 ] ) );
}

/**
 * A PayPal order with everything the panel shows.
 *
 * @param  array<string, mixed>  $paypal      `orders.meta.paypal` overrides.
 * @param  array<string, mixed>  $attributes  Order overrides.
 *
 * @return ArtisanPackUI\Ecommerce\Models\Order
 */
function panelOrder( array $paypal = [], array $attributes = [] ): ArtisanPackUI\Ecommerce\Models\Order
{
    return Orders::create( $attributes, array_replace_recursive( [
        'order_id'        => Orders::ORDER_ID,
        'mode'            => 'sandbox',
        'intent'          => 'authorize',
        'funding_source'  => 'card',
        'liability_shift' => 'POSSIBLE',
        'payer'           => [ 'email' => 'buyer@example.com' ],
        'card'            => [ 'brand' => 'VISA', 'last_digits' => '1111' ],
        'authorization'   => [ 'id' => Orders::AUTHORIZATION_ID, 'status' => 'CREATED', 'expires_at' => '2026-12-01T12:00:00+00:00' ],
        'disputes'        => [
            'PP-D-1' => [ 'id' => 'PP-D-1', 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED', 'status' => 'WAITING_FOR_SELLER_RESPONSE', 'amount' => '100.00', 'currency' => 'USD', 'respond_by' => '2026-11-22T10:00:00+00:00', 'url' => 'https://www.sandbox.paypal.com/resolutioncenter/view/PP-D-1', 'updated_at' => '2026-11-12T12:00:00+00:00' ],
        ],
    ], $paypal ) );
}

it( 'registers in admin-livewire\'s order panel registry as a side panel', function (): void {
    $panels = app( OrderPanelRegistry::class );

    expect( $panels->has( PayPalOrderPanel::PANEL_KEY ) )->toBeTrue()
        ->and( collect( $panels->forColumn( 'side' ) )->firstWhere( 'key', PayPalOrderPanel::PANEL_KEY ) )->toMatchArray( [
            'component' => PayPalOrderPanel::COMPONENT,
            'column'    => 'side',
            'position'  => PayPalOrderPanel::POSITION,
        ] );
} );

it( 'shows the mode, ids linked to the sandbox dashboard, payer, funding, liability shift, expiry, and disputes', function (): void {
    panelStaff();

    $order = panelOrder( [ 'capture_id' => Orders::CAPTURE_ID ] );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => $order ] )
        ->assertSeeHtml( 'data-paypal-panel-mode="sandbox"' )
        ->assertSee( [ 'Sandbox', Orders::ORDER_ID, Orders::CAPTURE_ID, Orders::AUTHORIZATION_ID ] )
        ->assertSeeHtml( 'href="https://www.sandbox.paypal.com/activity/payment/' . Orders::CAPTURE_ID . '"' )
        ->assertSeeHtml( 'href="https://www.sandbox.paypal.com/activity/payment/' . Orders::AUTHORIZATION_ID . '"' )
        ->assertDontSeeHtml( 'href="https://www.sandbox.paypal.com/activity/payment/' . Orders::ORDER_ID . '"' )
        ->assertSee( [ 'buyer@example.com', 'Card', 'Visa ending in 1111', 'Possible (3-D Secure passed)', 'Expires' ] )
        ->assertSeeHtml( 'data-paypal-panel-disputes="1"' )
        ->assertSee( [ 'PP-D-1', 'Waiting for your response', 'Item not received', '100.00 USD', 'Respond by', 'Created' ] )
        ->assertSeeHtml( 'data-paypal-panel-dispute-status="WAITING_FOR_SELLER_RESPONSE"' )
        ->assertSeeHtml( 'data-paypal-panel-dispute-reason="MERCHANDISE_OR_SERVICE_NOT_RECEIVED"' )
        ->assertSeeHtml( 'data-paypal-panel-authorization="CREATED"' )
        ->assertSeeHtml( 'data-paypal-panel-funding="card"' )
        ->assertSeeHtml( 'href="https://www.sandbox.paypal.com/resolutioncenter/view/PP-D-1"' );
} );

it( 'labels PayPal\'s statuses, reasons, and outcomes in words', function (): void {
    panelStaff();

    $order = panelOrder( [
        'authorization' => [ 'status' => 'PARTIALLY_CAPTURED' ],
        'disputes'      => [
            'PP-D-1' => [ 'status' => 'UNDER_REVIEW', 'reason' => 'UNAUTHORISED', 'outcome' => 'RESOLVED_SELLER_FAVOUR' ],
        ],
    ] );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => $order ] )
        ->assertSee( [ 'Under review', 'Unauthorized transaction', 'Outcome: In your favor', 'Partially captured' ] )
        ->assertDontSeeText( 'UNDER_REVIEW' )
        ->assertDontSeeText( 'UNDER REVIEW' )
        ->assertDontSeeText( 'RESOLVED SELLER FAVOUR' )
        ->assertSeeHtml( 'data-paypal-panel-dispute-status="UNDER_REVIEW"' )
        ->assertSeeHtml( 'data-paypal-panel-dispute-outcome="RESOLVED_SELLER_FAVOUR"' );
} );

it( 'makes values PayPal adds later readable', function (): void {
    panelStaff();

    $order = panelOrder( [
        'funding_source' => 'trustly_bank',
        'authorization'  => [ 'status' => 'NEWLY_INVENTED' ],
        'disputes'       => [
            'PP-D-1' => [ 'status' => 'AWAITING_ARBITRATION', 'reason' => 'SOMETHING_ELSE' ],
        ],
    ] );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => $order ] )
        ->assertSee( [ 'Trustly Bank', 'Newly Invented', 'Awaiting Arbitration', 'Something Else' ] )
        ->assertSeeHtml( 'data-paypal-panel-funding="trustly_bank"' );
} );

it( 'labels every documented PayPal value', function (): void {
    expect( PayPalOrderPanel::disputeStatus( 'WAITING_FOR_BUYER_RESPONSE' ) )->toBe( 'Waiting for the buyer' )
        ->and( PayPalOrderPanel::disputeReason( 'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED' ) )->toBe( 'Item not as described' )
        ->and( PayPalOrderPanel::disputeReason( 'CANCELED_RECURRING_BILLING' ) )->toBe( 'Canceled recurring billing' )
        ->and( PayPalOrderPanel::disputeOutcome( 'RESOLVED_BUYER_FAVOUR' ) )->toBe( 'In the buyer\'s favor' )
        ->and( PayPalOrderPanel::disputeOutcome( 'ACCEPTED' ) )->toBe( 'Accepted (the buyer was refunded)' )
        ->and( PayPalOrderPanel::disputeOutcome( 'NONE' ) )->toBe( 'None' )
        ->and( PayPalOrderPanel::authorizationStatus( 'VOIDED' ) )->toBe( 'Voided' );
} );

it( 'links live orders to the live dashboard', function (): void {
    panelStaff();

    Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder( [ 'mode' => 'live', 'capture_id' => Orders::CAPTURE_ID ] ) ] )
        ->assertSeeHtml( 'data-paypal-panel-mode="live"' )
        ->assertSeeHtml( 'href="https://www.paypal.com/activity/payment/' . Orders::CAPTURE_ID . '"' );
} );

it( 'flags an expired, uncaptured authorization and hides expiry once captured', function (): void {
    panelStaff();

    Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder( [ 'authorization' => [ 'expires_at' => '2026-11-01T12:00:00+00:00' ] ] ) ] )
        ->assertSee( 'Expired' );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder( [ 'authorization' => [ 'status' => 'CAPTURED' ] ] ) ] )
        ->assertSeeHtml( 'data-paypal-panel-authorization="CAPTURED"' )
        ->assertDontSeeHtml( 'data-paypal-panel-expires' );
} );

it( 'says there are no disputes', function (): void {
    panelStaff();

    Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder( [ 'disputes' => null ] ) ] )
        ->assertSeeHtml( 'data-paypal-panel-disputes="0"' )
        ->assertSee( 'None' );
} );

it( 'renders nothing for orders not paid with PayPal', function ( array $attributes, array $paypal ): void {
    panelStaff();

    $order = Orders::create( $attributes, $paypal );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => $order ] )
        ->assertSeeHtml( 'data-paypal-order-panel' )
        ->assertDontSee( 'PayPal Order' )
        ->assertDontSeeHtml( 'data-paypal-panel-mode' );
} )->with( [
    'another gateway'    => [ [ 'payment_gateway_key' => 'stripe' ], [ 'order_id' => 'X', 'mode' => 'sandbox' ] ],
    'no PayPal payment'  => [ [ 'payment_gateway_key' => 'paypal' ], [] ],
] );

it( 'requires order.view to mount, even for the customer who placed the order', function (): void {
    panelStaff( false );

    $customer = Customer::factory()->create( [ 'user_id' => 500 ] );

    Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder( [], [ 'customer_id' => $customer->id ] ) ] )->assertForbidden();
} );

it( 'requires order.view on every request', function (): void {
    panelStaff();

    $component = Livewire::test( PayPalOrderPanel::class, [ 'order' => panelOrder() ] )->assertOk();

    removeAllFilters( 'ap.ecommerce.abilities.order.view' );
    addFilter( 'ap.ecommerce.abilities.order.view', static fn (): bool => false );

    $component->call( 'refreshPanel' )->assertForbidden();
} );
