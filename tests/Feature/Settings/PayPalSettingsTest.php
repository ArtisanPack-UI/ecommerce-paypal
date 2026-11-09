<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Exceptions\SettingsWriteException;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Settings\PayPalSettings;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses( RefreshDatabase::class );

beforeEach( function (): void {
    Http::preventStrayRequests();

    $this->paypal   = PayPalFake::fake();
    $this->settings = app( SettingsRepository::class );
} );

/**
 * The constraint error codes for a write.
 *
 * @param  array<string, mixed>  $values  Values keyed by setting key.
 *
 * @return array<int, string>
 */
function paypalSettingErrors( array $values ): array
{
    try {
        app( SettingsRepository::class )->update( 'paypal', $values );
    } catch ( SettingsWriteException $exception ) {
        return array_column( $exception->errors, 'code' );
    }

    return [];
}

it( 'adds the paypal group with a translated label', function (): void {
    app()->setLocale( 'en' );

    $group = app( SettingsRegistry::class )->group( 'paypal' );

    expect( $group )->not->toBeNull()
        ->and( $group->label )->toBe( 'PayPal' );
} );

it( 'points every setting at the package config', function (): void {
    $definitions = app( SettingsRegistry::class )->definitions( 'paypal' );

    expect( array_keys( $definitions ) )->toContain(
        'paypal.enabled',
        'paypal.mode',
        'paypal.client_id',
        'paypal.intent',
        'paypal.brand_name',
        'paypal.soft_descriptor',
        'paypal.methods.paypal.enabled',
        'paypal.methods.paypal.pay_later',
        'paypal.methods.paypal.venmo',
        'paypal.methods.card.enabled',
        'paypal.methods.apple_pay.enabled',
        'paypal.methods.google_pay.enabled',
        'paypal.pay_later_messaging.checkout',
        'paypal.pay_later_messaging.cart',
        'paypal.pay_later_messaging.product',
        'paypal.vault.enabled',
    );

    foreach ( $definitions as $key => $definition ) {
        expect( $definition->configKey() )->toBe( 'artisanpack.ecommerce-paypal.' . substr( $key, strlen( 'paypal.' ) ) )
            ->and( config()->has( $definition->configKey() ) )->toBeTrue();
    }
} );

it( 'resolves the config defaults', function (): void {
    expect( $this->settings->values( 'paypal' ) )->toMatchArray( [
        'paypal.enabled'                => false,
        'paypal.mode'                   => 'sandbox',
        'paypal.intent'                 => 'capture',
        'paypal.methods.paypal.enabled' => true,
        'paypal.methods.card.enabled'   => false,
        'paypal.vault.enabled'          => false,
    ] )
        ->and( config( 'artisanpack.ecommerce-paypal.http' ) )->toBe( [ 'timeout' => 15, 'connect_timeout' => 5, 'retries' => 2 ] )
        ->and( config( 'artisanpack.ecommerce-paypal.webhook_verification' ) )->toBe( 'local' );
} );

it( 'applies a saved value to config', function (): void {
    $this->settings->update( 'paypal', [ 'paypal.intent' => 'authorize', 'paypal.methods.card.enabled' => true ] );

    expect( config( 'artisanpack.ecommerce-paypal.intent' ) )->toBe( 'authorize' )
        ->and( config( 'artisanpack.ecommerce-paypal.methods.card.enabled' ) )->toBeTrue();
} );

it( 'lists the client secret and webhook id as secrets without their values', function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_secret' => 'super-secret',
        'artisanpack.ecommerce-paypal.webhook_id'    => 'WH-1,WH-2',
    ] );

    $registry = app( SettingsRegistry::class );
    $secrets  = $registry->secrets( 'paypal' );

    expect( array_keys( $secrets ) )->toBe( [ 'artisanpack.ecommerce-paypal.client_secret', 'artisanpack.ecommerce-paypal.webhook_id' ] )
        ->and( $secrets['artisanpack.ecommerce-paypal.client_secret']->envName )->toBe( 'PAYPAL_CLIENT_SECRET' )
        ->and( $secrets['artisanpack.ecommerce-paypal.webhook_id']->envName )->toBe( 'PAYPAL_WEBHOOK_ID' )
        ->and( $registry->isSecret( 'artisanpack.ecommerce-paypal.client_secret' ) )->toBeTrue()
        ->and( json_encode( $this->settings->secrets( 'paypal' ) ) )->not->toContain( 'super-secret' )->not->toContain( 'WH-1' )
        ->and( json_encode( $this->settings->values( 'paypal' ) ) )->not->toContain( 'super-secret' );

    foreach ( $secrets as $secret ) {
        expect( $secret->toArray() )->toHaveKey( 'configured', true )->not->toHaveKey( 'value' );
    }
} );

it( 'refuses to store a secret', function (): void {
    expect( fn () => $this->settings->update( 'paypal', [ 'paypal.client_secret' => 'x' ] ) )->toThrow( SettingsWriteException::class );
} );

it( 'validates values', function ( string $key, mixed $value ): void {
    expect( fn () => $this->settings->update( 'paypal', [ $key => $value ] ) )->toThrow( SettingsWriteException::class );
} )->with( [
    'unknown mode'           => [ 'paypal.mode', 'production' ],
    'unknown intent'         => [ 'paypal.intent', 'sale' ],
    'long soft descriptor'   => [ 'paypal.soft_descriptor', str_repeat( 'x', 23 ) ],
] );

it( 'refuses to enable PayPal without credentials', function (): void {
    expect( paypalSettingErrors( [ 'paypal.enabled' => true ] ) )->toBe( [ 'paypal-not-configured' ] );

    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'secret' ] );

    expect( paypalSettingErrors( [ 'paypal.enabled' => true, 'paypal.client_id' => 'id' ] ) )->toBe( [] );
} );

it( 'refuses live mode with credentials PayPal refuses', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'sandbox-secret' ] );

    $this->paypal->error( 'oauth.token', 'invalid-client' );

    expect( paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'sandbox-id' ] ) )->toBe( [ 'paypal-live-credentials-invalid' ] );

    $this->paypal->assertSent( 'oauth.token', fn ( $request ): bool => str_starts_with( $request->url(), 'https://api-m.paypal.com/' ) );
} );

it( 'accepts live mode with working live credentials', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'live-secret' ] );

    expect( paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'live-id' ] ) )->toBe( [] )
        ->and( config( 'artisanpack.ecommerce-paypal.mode' ) )->toBe( 'live' );
} );

it( 'caches the live credential check for 10 minutes', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'sandbox-secret' ] );

    $this->paypal->error( 'oauth.token', 'invalid-client' );

    paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'sandbox-id' ] );
    $second = paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'sandbox-id' ] );

    expect( $second )->toBe( [ 'paypal-live-credentials-invalid' ] );
    $this->paypal->assertSentCount( 'oauth.token', 1 );

    $this->travel( PayPalSettings::CREDENTIAL_CHECK_TTL + 1 )->seconds();

    // The default fixture now issues a token, so the credentials pass.
    expect( paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'sandbox-id' ] ) )->toBe( [] );
    $this->paypal->assertSentCount( 'oauth.token', 2 );
} );

it( 'never caches the secret itself', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'live-secret' ] );

    paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'live-id' ] );

    $store = Cache::getStore();
    $keys  = array_keys( ( new ReflectionProperty( $store, 'storage' ) )->getValue( $store ) );

    expect( implode( ',', $keys ) )->not->toContain( 'live-secret' );
} );

it( 'does not block a write when PayPal cannot be reached', function (): void {
    config( [ 'artisanpack.ecommerce-paypal.client_secret' => 'live-secret', 'artisanpack.ecommerce-paypal.http.retries' => 0 ] );

    $this->paypal->timeout( 'oauth.token' );

    expect( paypalSettingErrors( [ 'paypal.mode' => 'live', 'paypal.client_id' => 'live-id' ] ) )->toBe( [] );
} );

it( 'refuses saved payment methods without PayPal or cards', function (): void {
    expect( paypalSettingErrors( [ 'paypal.vault.enabled' => true, 'paypal.methods.paypal.enabled' => false, 'paypal.methods.card.enabled' => false ] ) )
        ->toBe( [ 'paypal-vault-unavailable' ] )
        ->and( paypalSettingErrors( [ 'paypal.vault.enabled' => true, 'paypal.methods.paypal.enabled' => false, 'paypal.methods.card.enabled' => true ] ) )
        ->toBe( [] );
} );

it( 'refuses Apple Pay without a domain association file', function (): void {
    expect( paypalSettingErrors( [ 'paypal.methods.apple_pay.enabled' => true ] ) )->toBe( [ 'paypal-apple-pay-domain-missing' ] )
        ->and( paypalSettingErrors( [ 'paypal.methods.apple_pay.enabled' => true, 'paypal.methods.apple_pay.domain_association' => 'https://example.com/file' ] ) )->toBe( [] );
} );

it( 'checks the combined state, including keys from other writes', function (): void {
    $this->settings->update( 'paypal', [ 'paypal.methods.paypal.enabled' => false, 'paypal.methods.card.enabled' => true, 'paypal.vault.enabled' => true ] );

    expect( paypalSettingErrors( [ 'paypal.methods.card.enabled' => false ] ) )->toBe( [ 'paypal-vault-unavailable' ] );
} );

it( 'hides the settings while the satellite is uninstalled', function (): void {
    Cache::forever( 'artisanpack.ecommerce.satellites.uninstalled', [ EcommercePayPalServiceProvider::PACKAGE_NAME ] );

    $this->app->forgetInstance( SatelliteRegistry::class );
    $this->app->singleton( SatelliteRegistry::class, static fn ( $app ): SatelliteRegistry => new SatelliteRegistry( $app ) );
    $this->app->forgetInstance( SettingsRegistry::class );
    $this->app->singleton( SettingsRegistry::class, static fn ( $app ): SettingsRegistry => new SettingsRegistry( $app ) );

    ( new EcommercePayPalServiceProvider( $this->app ) )->boot();

    expect( app( SettingsRegistry::class )->hasGroup( 'paypal' ) )->toBeFalse()
        ->and( app( SettingsRegistry::class )->definitions( 'paypal' ) )->toBe( [] );
} );
