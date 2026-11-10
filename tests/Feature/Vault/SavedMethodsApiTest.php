<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\PayPalWebhooks;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Sleep::fake();

    $this->paypal = PayPalFake::fake();

    PayPalWebhooks::boot( [ 'vault.enabled' => true ] );

    $this->customer = Customer::factory()->create( [ 'user_id' => 7 ] );
} );

/**
 * Signs in user 7 through the API guard.
 *
 * @return void
 */
function apiShopper(): void
{
    test()->actingAs( new GenericUser( [ 'id' => 7 ] ), 'sanctum' );
}

/**
 * Headers for a write.
 *
 * @return array<string, string>
 */
function apiWrite(): array
{
    return [ 'Idempotency-Key' => (string) Str::uuid() ];
}

it( 'registers the routes on the engine\'s REST stack and rate policy', function ( string $name, string $method, string $uri ): void {
    $route = Route::getRoutes()->getByName( $name );

    expect( $route )->not->toBeNull()
        ->and( $route->methods() )->toContain( $method )
        ->and( $route->uri() )->toBe( $uri )
        ->and( $route->gatherMiddleware() )->toContain( 'auth:sanctum', 'ecommerce.rate-limit:ecommerce.admin.mutate', 'ecommerce.json' );

    if ( 'GET' !== $method ) {
        expect( $route->gatherMiddleware() )->toContain( 'ecommerce.idempotency' );
    }
} )->with( [
    'list'    => [ 'ecommerce.api.me.paypal.payment-methods.index', 'GET', 'api/ecommerce/v1/me/paypal/payment-methods' ],
    'delete'  => [ 'ecommerce.api.me.paypal.payment-methods.destroy', 'DELETE', 'api/ecommerce/v1/me/paypal/payment-methods/{token}' ],
    'default' => [ 'ecommerce.api.me.paypal.payment-methods.default', 'POST', 'api/ecommerce/v1/me/paypal/payment-methods/{token}/default' ],
] );

it( 'lists the shopper\'s methods for this mode, default first, without PayPal token ids', function (): void {
    apiShopper();

    $card    = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id, 'paypal_token_id' => 'tok-secret', 'display' => [ 'brand' => 'VISA', 'last_digits' => '1111', 'expiry' => '2030-12' ] ] );
    $default = PayPalPaymentToken::factory()->paypal()->default()->create( [ 'customer_id' => $this->customer->id, 'display' => [ 'email' => 'ada@example.test' ] ] );
    PayPalPaymentToken::factory()->live()->create( [ 'customer_id' => $this->customer->id ] );
    PayPalPaymentToken::factory()->create();

    $response = $this->getJson( '/api/ecommerce/v1/me/paypal/payment-methods' )
        ->assertOk()
        ->assertJsonCount( 2, 'data' )
        ->assertJsonPath( 'data.0.id', $default->id )
        ->assertJsonPath( 'data.0.type', 'paypal_payment_method' )
        ->assertJsonPath( 'data.0.method', 'paypal' )
        ->assertJsonPath( 'data.0.is_default', true )
        ->assertJsonPath( 'data.0.email', 'ada@example.test' )
        ->assertJsonPath( 'data.1.id', $card->id )
        ->assertJsonPath( 'data.1.label', 'Visa ending in 1111' )
        ->assertJsonPath( 'data.1.last_digits', '1111' )
        ->assertJsonPath( 'data.1.expiry', '2030-12' )
        ->assertJsonPath( 'data.1.expired', false );

    expect( $response->getContent() )->not->toContain( 'tok-secret' );
} );

it( 'requires authentication', function (): void {
    $this->getJson( '/api/ecommerce/v1/me/paypal/payment-methods' )->assertUnauthorized();
} );

it( 'answers 404 for an account with no customer record', function (): void {
    $this->actingAs( new GenericUser( [ 'id' => 99 ] ), 'sanctum' );

    $this->getJson( '/api/ecommerce/v1/me/paypal/payment-methods' )->assertNotFound();
} );

it( 'deletes a method at PayPal, then here', function (): void {
    apiShopper();

    $card = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id, 'paypal_token_id' => 'tok-del' ] );

    $this->deleteJson( '/api/ecommerce/v1/me/paypal/payment-methods/' . $card->id, [], apiWrite() )->assertNoContent();

    $this->paypal->assertSent( 'vault.payment_tokens.delete', fn ( Request $request ): bool => str_ends_with( $request->url(), '/tok-del' ) );

    expect( $card->refresh()->trashed() )->toBeTrue();
} );

it( 'keeps the method and answers 502 when PayPal can\'t delete it', function (): void {
    Exceptions::fake();
    apiShopper();

    $card = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id ] );

    $this->paypal->error( 'vault.payment_tokens.delete', 'internal-server-error', times: 3 );

    $this->deleteJson( '/api/ecommerce/v1/me/paypal/payment-methods/' . $card->id, [], apiWrite() )
        ->assertStatus( 502 )
        ->assertJsonPath( 'title', 'PayPal unavailable' );

    expect( $card->refresh()->trashed() )->toBeFalse();
} );

it( 'makes a method the default', function (): void {
    apiShopper();

    $old = PayPalPaymentToken::factory()->default()->create( [ 'customer_id' => $this->customer->id ] );
    $new = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id ] );

    $this->postJson( '/api/ecommerce/v1/me/paypal/payment-methods/' . $new->id . '/default', [], apiWrite() )
        ->assertOk()
        ->assertJsonPath( 'data.id', $new->id )
        ->assertJsonPath( 'data.is_default', true );

    expect( $old->refresh()->is_default )->toBeFalse();
} );

it( 'answers 404 for another customer\'s method, and leaves it alone', function ( string $method, string $suffix ): void {
    apiShopper();

    $theirs = PayPalPaymentToken::factory()->create( [ 'customer_id' => Customer::factory()->create()->id ] );

    $this->json( $method, '/api/ecommerce/v1/me/paypal/payment-methods/' . $theirs->id . $suffix, [], apiWrite() )->assertNotFound();

    expect( $theirs->refresh()->trashed() )->toBeFalse()
        ->and( $theirs->is_default )->toBeFalse();

    $this->paypal->assertNotSent( 'vault.payment_tokens.delete' );
} )->with( [
    'delete'  => [ 'DELETE', '' ],
    'default' => [ 'POST', '/default' ],
] );

it( 'requires an idempotency key on writes', function (): void {
    apiShopper();

    $card = PayPalPaymentToken::factory()->create( [ 'customer_id' => $this->customer->id ] );

    $this->deleteJson( '/api/ecommerce/v1/me/paypal/payment-methods/' . $card->id )->assertStatus( 400 );

    expect( $card->refresh()->trashed() )->toBeFalse();
} );
