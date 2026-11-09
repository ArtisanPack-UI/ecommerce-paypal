<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Support\ApplePayDomainAssociation;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

const APPLE_PAY_ROUTE = 'artisanpack.ecommerce-paypal.apple-pay.domain-association';

beforeEach( function (): void {
    $this->directory = sys_get_temp_dir() . '/ecommerce-paypal-apple-' . uniqid();

    File::ensureDirectoryExists( $this->directory );
} );

afterEach( function (): void {
    File::deleteDirectory( $this->directory );
    File::delete( ApplePayDomainAssociation::publishedPath() );
} );

/**
 * Sets the domain association setting.
 *
 * @param  string|null  $value  Setting.
 *
 * @return void
 */
function appleDomainAssociation( ?string $value ): void
{
    config( [ 'artisanpack.ecommerce-paypal.methods.apple_pay.domain_association' => $value ] );
}

it( 'serves the configured contents as plain text at the well-known path', function (): void {
    appleDomainAssociation( '7B227073704964223A2238' );

    $this->get( ApplePayDomainAssociation::PATH )
        ->assertOk()
        ->assertHeader( 'Content-Type', 'text/plain; charset=UTF-8' )
        ->assertSeeText( '7B227073704964223A2238' )
        ->assertCookieMissing( 'laravel_session' );

    expect( route( APPLE_PAY_ROUTE, absolute: false ) )->toBe( ApplePayDomainAssociation::PATH );
} );

it( 'serves a configured file named like Apple\'s', function (): void {
    $path = $this->directory . '/' . ApplePayDomainAssociation::FILE_NAME;

    File::put( $path, "7B2270737049642223A2241424344454630313233343536373839414243444546\n" );
    appleDomainAssociation( $path );

    $this->get( ApplePayDomainAssociation::PATH )->assertOk()->assertSeeText( '7B2270737049642223A2241424344454630313233343536373839414243444546' );
} );

it( 'serves the published file when nothing is configured', function (): void {
    File::ensureDirectoryExists( dirname( ApplePayDomainAssociation::publishedPath() ) );
    File::put( ApplePayDomainAssociation::publishedPath(), '7B22707370496422303132333435363738394142434445460A' );
    appleDomainAssociation( null );

    expect( ApplePayDomainAssociation::source() )->toBe( 'file' );

    $this->get( ApplePayDomainAssociation::PATH )->assertOk()->assertSeeText( '7B22707370496422303132333435363738394142434445460A' );
} );

it( 'fetches the file from an HTTPS URL and caches it', function (): void {
    Http::fake( [ 'https://files.example/association' => Http::response( '7B227073704964223A2230313233343536373839414243444546', 200 ) ] );
    appleDomainAssociation( 'https://files.example/association' );

    $this->get( ApplePayDomainAssociation::PATH )->assertOk()->assertSeeText( '7B227073704964223A2230313233343536373839414243444546' );
    $this->get( ApplePayDomainAssociation::PATH )->assertOk()->assertSeeText( '7B227073704964223A2230313233343536373839414243444546' );

    Http::assertSentCount( 1 );
} );

it( 'remembers a failed fetch for a minute instead of calling the URL on every request', function (): void {
    Http::fake( [ 'https://files.example/association' => Http::sequence()
        ->push( 'Server error', 500 )
        ->push( '7B227073704964223A2230313233343536373839414243444546', 200 ) ] );
    appleDomainAssociation( 'https://files.example/association' );

    $this->get( ApplePayDomainAssociation::PATH )->assertNotFound();
    $this->get( ApplePayDomainAssociation::PATH )->assertNotFound();

    Http::assertSentCount( 1 );

    $this->travel( ApplePayDomainAssociation::FAILURE_CACHE_SECONDS + 1 )->seconds();

    $this->get( ApplePayDomainAssociation::PATH )->assertOk();

    Http::assertSentCount( 2 );
} );

it( 'answers 404 when nothing usable is configured', function ( Closure $arrange ): void {
    $arrange( $this );

    $this->get( ApplePayDomainAssociation::PATH )->assertNotFound();
} )->with( [
    'nothing'             => fn () => fn () => appleDomainAssociation( null ),
    'another file'        => fn () => function ( $test ): void {
        File::put( $test->directory . '/.env', 'APP_KEY=secret' );
        appleDomainAssociation( $test->directory . '/.env' );
    },
    'missing file'        => fn () => fn ( $test ) => appleDomainAssociation( $test->directory . '/' . ApplePayDomainAssociation::FILE_NAME ),
    'plain http url'      => fn () => fn () => appleDomainAssociation( 'http://files.example/association' ),
    'non-hex url body'    => fn () => function (): void {
        Http::fake( [ 'https://internal.example/*' => Http::response( '{"secret":"value"}', 200 ) ] );
        appleDomainAssociation( 'https://internal.example/status' );
    },
    'non-hex named file'  => fn () => function ( $test ): void {
        File::put( $test->directory . '/' . ApplePayDomainAssociation::FILE_NAME, "APP_KEY=secret\n" );
        appleDomainAssociation( $test->directory . '/' . ApplePayDomainAssociation::FILE_NAME );
    },
    'unreachable url'     => fn () => function (): void {
        Http::fake( [ 'https://files.example/*' => Http::response( '<html>Not found</html>', 404 ) ] );
        appleDomainAssociation( 'https://files.example/association' );
    },
] );

it( 'can be switched off so the web server serves the file', function (): void {
    $provider = new EcommercePayPalServiceProvider( app() );
    $register = ( new ReflectionMethod( $provider, 'registerRoutes' ) );

    app( 'router' )->setRoutes( new RouteCollection() );
    config( [ 'artisanpack.ecommerce-paypal.methods.apple_pay.serve_domain_association' => false ] );
    $register->invoke( $provider );

    expect( Route::has( APPLE_PAY_ROUTE ) )->toBeFalse();

    config( [ 'artisanpack.ecommerce-paypal.methods.apple_pay.serve_domain_association' => true ] );
    $register->invoke( $provider );
    app( 'router' )->getRoutes()->refreshNameLookups();

    expect( Route::has( APPLE_PAY_ROUTE ) )->toBeTrue();
} );
