<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalSavedMethodException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalCardFields;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\PayPalTestOrders as Orders;
use Tests\Support\PayPalWebhooks;
use Tests\Support\Shoppers;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );
} );

/**
 * The card gateway.
 *
 * @return PayPalGateway
 */
function savedCardGateway(): PayPalGateway
{
    return app( PaymentGatewayRegistry::class )->get( PayPalCardGateway::KEY );
}

/**
 * A saved card of `$customer`.
 *
 * @param  Customer              $customer    Customer.
 * @param  array<string, mixed>  $attributes  Overrides.
 *
 * @return PayPalPaymentToken
 */
function savedCard( Customer $customer, array $attributes = [] ): PayPalPaymentToken
{
    return PayPalPaymentToken::factory()->create( array_merge( [ 'customer_id' => $customer->id ], $attributes ) );
}

/**
 * A PayPal card Order paid with a vaulted card, as PayPal answers the
 * create call when no 3-D Secure step is needed.
 *
 * @param  string  $id  Order id.
 *
 * @return array<string, mixed>
 */
function vaultedCardOrder( string $id = 'VAULT-ORDER' ): array
{
    return Orders::body( 'orders/captured', [
        'id'                                                => $id,
        'payment_source'                                    => [ 'card' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ] ],
        'payer'                                             => null,
        'purchase_units.0.amount.value'                     => '25.00',
        'purchase_units.0.payments.captures.0.amount.value' => '25.00',
    ] );
}

/**
 * Mounts the card fields driver the way the checkout does for `$cart`.
 *
 * @param  Cart  $cart  Cart.
 *
 * @return Testable
 */
function savedCardDriver( Cart $cart ): Testable
{
    test()->paypal->respond( 'orders.get', Orders::body( 'orders/created', [ 'purchase_units.0.amount.value' => '25.00' ] ) );

    $config = savedCardGateway()->clientConfig( $cart, savedCardGateway()->retrievePaymentSession( (string) $cart->payment_reference ) );

    return Livewire::test( PayPalCardFields::class, [ 'config' => $config, 'gateway' => PayPalCardGateway::KEY, 'gatewayLabel' => 'Card', 'reference' => (string) $cart->payment_reference, 'token' => 'action-token' ] );
}

/**
 * PayPal's answer when a vault id no longer exists.
 *
 * @param  string|null  $field  The field PayPal names, or null for none.
 *
 * @return array<string, mixed>
 */
function invalidVaultId( ?string $field = '/payment_source/card/vault_id' ): array
{
    $body = Orders::issue( 'INVALID_RESOURCE_ID' );

    if ( null !== $field ) {
        $body['details'][0]['field'] = $field;
    }

    return $body;
}

describe( 'order payload', function (): void {
    it( 'pays with the saved card\'s vault id as a customer-initiated stored credential', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer, [ 'paypal_token_id' => 'tok-visa' ] );

        app( PayPalVault::class )->rememberChoice( $cart, true );

        $source = app( OrderPayloadBuilder::class )->build( $cart->load( 'items' ), savedCardGateway(), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] )['payment_source']['card'];

        expect( $source['vault_id'] )->toBe( 'tok-visa' )
            ->and( $source['stored_credential'] )->toBe( [ 'payment_initiator' => 'CUSTOMER', 'payment_type' => 'ONE_TIME', 'usage' => 'SUBSEQUENT' ] )
            ->and( $source['attributes'] )->toBe( [ 'verification' => [ 'method' => 'SCA_WHEN_REQUIRED' ] ] );
    } );

    it( 'refuses a saved card the shopper can\'t use', function ( array $setup ): void {
        [ $cart, $tokenId ] = $setup;

        app( OrderPayloadBuilder::class )->build( $cart->load( 'items' ), savedCardGateway(), [ PayPalVault::SAVED_TOKEN_CONTEXT => $tokenId ] );
    } )->with( [
        'another customer\'s card' => function (): array {
            $cart = Shoppers::cart( Shoppers::signIn(), 'paypal-card' );

            return [ $cart, savedCard( Customer::factory()->create() )->id ];
        },
        'a guest'                  => function (): array {
            $customer = Customer::factory()->create();

            return [ Shoppers::cart( $customer, 'paypal-card' ), savedCard( $customer )->id ];
        },
        'a removed card'           => function (): array {
            $customer = Shoppers::signIn();
            $card     = savedCard( $customer );
            $card->delete();

            return [ Shoppers::cart( $customer, 'paypal-card' ), $card->id ];
        },
        'a live-mode card'         => function (): array {
            $customer = Shoppers::signIn();

            return [ Shoppers::cart( $customer, 'paypal-card' ), savedCard( $customer, [ 'mode' => 'live' ] )->id ];
        },
        'a saved PayPal wallet'    => function (): array {
            $customer = Shoppers::signIn();

            return [ Shoppers::cart( $customer, 'paypal-card' ), PayPalPaymentToken::factory()->paypal()->create( [ 'customer_id' => $customer->id ] )->id ];
        },
        'an unknown id'            => fn (): array => [ Shoppers::cart( Shoppers::signIn(), 'paypal-card' ), 'nope' ],
    ] )->throws( PayPalSavedMethodException::class );

    it( 'refuses a saved card when vaulting is off', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer );

        config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

        app( OrderPayloadBuilder::class )->build( $cart->load( 'items' ), savedCardGateway(), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] );
    } )->throws( PayPalSavedMethodException::class );
} );

describe( 'payment session', function (): void {
    it( 'creates the Order with a request id of its own and records the card', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer );

        $cart->forceFill( [ 'payment_reference' => null ] )->save();

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'id' => 'FIELDS-ORDER' ] ) );
        $this->paypal->respond( 'orders.create', vaultedCardOrder() );

        savedCardGateway()->createPaymentSession( $cart->load( 'items' ) );
        $session = savedCardGateway()->createPaymentSession( $cart->load( 'items' ), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] );

        $ids = array_map( static fn ( Request $request ): string => $request->header( 'PayPal-Request-Id' )[0], $this->paypal->sent( 'orders.create' ) );

        expect( $session->reference )->toBe( 'VAULT-ORDER' )
            ->and( $session->isConfirmed() )->toBeTrue()
            ->and( $ids[0] )->not->toBe( $ids[1] )
            ->and( $cart->refresh()->meta['paypal'][ PayPalVault::SAVED_TOKEN_META ] )->toBe( [ 'id' => $card->id, 'order_id' => 'VAULT-ORDER' ] );
    } );

    it( 'removes a card PayPal reports invalid, promoting the next default, and tells the shopper', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer, [ 'is_default' => true ] );
        $other    = savedCard( $customer );

        $this->paypal->respond( 'orders.create', invalidVaultId(), 422 );

        expect( fn () => savedCardGateway()->createPaymentSession( $cart->load( 'items' ), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] ) )
            ->toThrow( PayPalSavedMethodException::class, 'That saved card can no longer be used, so we removed it. Choose another card or enter a new one.' );

        expect( $card->refresh()->trashed() )->toBeTrue()
            ->and( $other->refresh()->is_default )->toBeTrue();
    } );

    it( 'keeps the card when PayPal\'s not-found error isn\'t about the vault id', function ( array $body, int $status ): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer );

        $this->paypal->respond( 'orders.create', $body, $status );

        expect( fn () => savedCardGateway()->createPaymentSession( $cart->load( 'items' ), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] ) )
            ->toThrow( ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException::class );

        expect( $card->refresh()->trashed() )->toBeFalse();
    } )->with( [
        'invalid id elsewhere' => [ invalidVaultId( '/purchase_units/0/payee/merchant_id' ), 422 ],
        'invalid id, no field' => [ invalidVaultId( null ), 422 ],
        'plain 404'            => [ PayPalFake::fixture( 'errors/resource-not-found' ), 404 ],
    ] );

    it( 'keeps the card when PayPal fails for another reason', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer );

        $this->paypal->error( 'orders.create', 'internal-server-error', times: 3 );

        expect( fn () => savedCardGateway()->createPaymentSession( $cart->load( 'items' ), [ PayPalVault::SAVED_TOKEN_CONTEXT => $card->id ] ) )
            ->toThrow( ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException::class );

        expect( $card->refresh()->trashed() )->toBeFalse();
    } );
} );

describe( 'capture', function (): void {
    it( 'updates the saved card\'s last use once the payment succeeds', function (): void {
        Carbon::setTestNow( '2026-11-09 12:00:00' );

        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer, [ 'last_used_at' => '2026-01-01 00:00:00' ] );

        $cart->forceFill( [ 'payment_reference' => 'VAULT-ORDER' ] )->save();
        app( PayPalVault::class )->rememberSavedCard( $cart, $card, 'VAULT-ORDER' );

        $this->paypal->respond( 'orders.get', vaultedCardOrder() );

        $order  = Orders::create( [ 'customer_id' => $customer->id, 'payment_gateway_key' => 'paypal-card', 'payment_reference' => 'VAULT-ORDER', 'total_amount' => 2500, 'subtotal_amount' => 2500 ] );
        $result = savedCardGateway()->capturePayment( $order, Orders::session( 'paypal-card', 2500 ) );

        expect( $result->success )->toBeTrue()
            ->and( $card->refresh()->last_used_at->toDateTimeString() )->toBe( '2026-11-09 12:00:00' );

        $this->paypal->assertNotSent( 'orders.capture' );
    } );

    it( 'leaves other cards alone', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer );

        $cart->forceFill( [ 'payment_reference' => 'VAULT-ORDER' ] )->save();
        app( PayPalVault::class )->rememberSavedCard( $cart, $card, 'SOME-OTHER-ORDER' );

        $this->paypal->respond( 'orders.get', vaultedCardOrder() );

        savedCardGateway()->capturePayment( Orders::create( [ 'customer_id' => $customer->id, 'payment_gateway_key' => 'paypal-card', 'payment_reference' => 'VAULT-ORDER', 'total_amount' => 2500, 'subtotal_amount' => 2500 ] ), Orders::session( 'paypal-card', 2500 ) );

        expect( $card->refresh()->last_used_at )->toBeNull();
    } );
} );

describe( 'card fields driver', function (): void {
    it( 'lists the shopper\'s saved cards for this mode, default first, with a new-card option', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );

        savedCard( $customer, [ 'display' => [ 'brand' => 'MASTERCARD', 'last_digits' => '4444', 'expiry' => '2031-01' ] ] );
        $default = savedCard( $customer, [ 'is_default' => true, 'display' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ] ] );
        savedCard( $customer, [ 'mode' => 'live', 'display' => [ 'brand' => 'AMEX', 'last_digits' => '0005' ] ] );
        savedCard( Customer::factory()->create(), [ 'display' => [ 'brand' => 'DISCOVER', 'last_digits' => '9999' ] ] );
        PayPalPaymentToken::factory()->paypal()->create( [ 'customer_id' => $customer->id ] );

        $component = savedCardDriver( $cart )
            ->assertSet( 'savedCard', (string) $default->id )
            ->assertSeeHtml( 'data-paypal-saved-cards' )
            ->assertSeeHtml( 'data-paypal-saved-card-pay' )
            ->assertSeeInOrder( [ 'Visa ending in 1111', 'Expires 12/2030 · Default', 'Mastercard ending in 4444', 'Use a new card' ] )
            ->assertDontSee( [ 'Amex ending in 0005', 'Discover ending in 9999' ] )
            ->assertDontSeeHtml( 'data-paypal-save-method' );

        expect( $component->html() )->toMatch( '/class="hidden"[^>]*data-paypal-new-card/' );

        $component->set( 'savedCard', PayPalCardFields::NEW_CARD )
            ->assertDontSeeHtml( 'data-paypal-saved-card-pay' )
            ->assertSeeHtml( 'data-paypal-save-method' );

        $this->paypal->assertNotSent( 'orders.create' );
    } );

    it( 'shows no saved cards to guests or when vaulting is off', function ( Cart $cart ): void {
        savedCardDriver( $cart )
            ->assertSet( 'savedCard', PayPalCardFields::NEW_CARD )
            ->assertDontSeeHtml( 'data-paypal-saved-cards' );
    } )->with( [
        'guest'     => function (): Cart {
            $customer = Customer::factory()->create();
            savedCard( $customer );

            return Shoppers::cart( $customer, 'paypal-card' );
        },
        'vault off' => function (): Cart {
            $customer = Shoppers::signIn();
            savedCard( $customer );
            config( [ 'artisanpack.ecommerce-paypal.vault.enabled' => false ] );

            return Shoppers::cart( $customer, 'paypal-card' );
        },
    ] );

    it( 'pays with the chosen saved card and confirms the payment', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer, [ 'paypal_token_id' => 'tok-visa', 'is_default' => true ] );

        $component = savedCardDriver( $cart );

        $this->paypal->respond( 'orders.create', vaultedCardOrder() );

        $component->call( 'payWithSavedCard' )
            ->assertSet( 'reference', 'VAULT-ORDER' )
            ->assertSet( 'payingWithSaved', true )
            ->assertDispatched( 'payment-confirmed', reference: 'VAULT-ORDER', gateway: 'paypal-card', token: 'action-token' )
            ->assertNotDispatched( 'payment-failed' );

        expect( $cart->refresh()->payment_reference )->toBe( 'VAULT-ORDER' );

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => 'tok-visa' === data_get( $request->data(), 'payment_source.card.vault_id' )
            && 'CUSTOMER' === data_get( $request->data(), 'payment_source.card.stored_credential.payment_initiator' )
            && 'SUBSEQUENT' === data_get( $request->data(), 'payment_source.card.stored_credential.usage' ) );
    } );

    it( 'confirms a saved-card payment PayPal already charged, whatever the 3-D Secure result', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );

        savedCard( $customer, [ 'is_default' => true ] );

        $component = savedCardDriver( $cart );
        $order     = vaultedCardOrder();

        $order['payment_source']['card']['authentication_result'] = [ 'liability_shift' => 'UNKNOWN' ];
        $this->paypal->respond( 'orders.create', $order );

        $component->call( 'payWithSavedCard' )
            ->assertDispatched( 'payment-confirmed', reference: 'VAULT-ORDER' )
            ->assertNotDispatched( 'payment-failed' );
    } );

    it( 'still refuses an uncharged saved-card payment without a liability shift', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );

        savedCard( $customer, [ 'is_default' => true ] );

        $component = savedCardDriver( $cart );
        $approved  = Orders::approvedCard( 'NO' );

        $approved['id']                                   = 'VAULT-ORDER';
        $approved['purchase_units'][0]['amount']['value'] = '25.00';
        $this->paypal->respond( 'orders.create', $approved );

        $component->call( 'payWithSavedCard' )
            ->assertDispatched( 'payment-failed' )
            ->assertNotDispatched( 'payment-confirmed' );
    } );

    it( 'sends the shopper to verify the card when the bank asks for 3-D Secure', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );

        savedCard( $customer, [ 'is_default' => true ] );

        $component = savedCardDriver( $cart );

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [
            'id'                            => 'VAULT-ORDER',
            'status'                        => 'PAYER_ACTION_REQUIRED',
            'purchase_units.0.amount.value' => '25.00',
            'links'                         => [ [ 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/webapps/helios?action=verify&flow=3ds', 'method' => 'GET' ] ],
        ] ) );

        $component->call( 'payWithSavedCard' )
            ->assertRedirect( 'https://www.sandbox.paypal.com/webapps/helios?action=verify&flow=3ds' )
            ->assertNotDispatched( 'payment-confirmed' );
    } );

    it( 'never pays with another customer\'s card, even when the browser names it', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $theirs   = savedCard( Customer::factory()->create() );

        savedCard( $customer );

        savedCardDriver( $cart )
            ->set( 'savedCard', (string) $theirs->id )
            ->assertSet( 'savedCard', PayPalCardFields::NEW_CARD )
            ->call( 'payWithSavedCard' )
            ->assertDispatched( 'payment-failed' )
            ->assertNotDispatched( 'payment-confirmed' );

        $this->paypal->assertNotSent( 'orders.create' );

        expect( $cart->refresh()->payment_reference )->toBe( Orders::ORDER_ID );
    } );

    it( 'tells the shopper when PayPal rejects the saved card and keeps the card-fields Order', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );
        $card     = savedCard( $customer, [ 'is_default' => true ] );

        $component = savedCardDriver( $cart );

        $this->paypal->respond( 'orders.create', invalidVaultId(), 422 );

        $component->call( 'payWithSavedCard' )
            ->assertSet( 'savedCard', PayPalCardFields::NEW_CARD )
            ->assertSet( 'reference', Orders::ORDER_ID )
            ->assertDispatched( 'payment-failed', message: 'That saved card can no longer be used, so we removed it. Choose another card or enter a new one.' )
            ->assertDontSeeHtml( 'data-paypal-saved-cards' );

        expect( $card->refresh()->trashed() )->toBeTrue()
            ->and( $cart->refresh()->payment_reference )->toBe( Orders::ORDER_ID );
    } );

    it( 'goes back to a card-fields Order when the shopper switches to a new card after a saved-card attempt', function (): void {
        $customer = Shoppers::signIn();
        $cart     = Shoppers::cart( $customer, 'paypal-card' );

        savedCard( $customer, [ 'is_default' => true ] );

        $component = savedCardDriver( $cart );

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'id' => 'VAULT-ORDER', 'status' => 'CREATED', 'purchase_units.0.amount.value' => '25.00' ] ) );
        $component->call( 'payWithSavedCard' )->assertDispatched( 'payment-failed' );

        $this->paypal->respond( 'orders.create', Orders::body( 'orders/created', [ 'id' => 'FIELDS-ORDER', 'purchase_units.0.amount.value' => '25.00' ] ) );

        $component->set( 'savedCard', PayPalCardFields::NEW_CARD )
            ->assertSet( 'reference', 'FIELDS-ORDER' )
            ->assertSet( 'payingWithSaved', false )
            ->assertDispatched( 'paypal-order-changed', orderId: 'FIELDS-ORDER' );

        $this->paypal->assertSent( 'orders.create', fn ( Request $request ): bool => null === data_get( $request->data(), 'payment_source.card.vault_id' ) );
    } );
} );
