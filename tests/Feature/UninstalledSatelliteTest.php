<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use Illuminate\Support\Facades\Cache;

beforeEach( function (): void {
    // Simulate `ecommerce:satellite:uninstall`, then boot a fresh provider.
    Cache::forever( 'artisanpack.ecommerce.satellites.uninstalled', [ EcommercePayPalServiceProvider::PACKAGE_NAME ] );

    $this->app->forgetInstance( SatelliteRegistry::class );
    $this->app->singleton( SatelliteRegistry::class, static fn ( $app ): SatelliteRegistry => new SatelliteRegistry( $app ) );

    $this->app['view']->getFinder()->replaceNamespace( 'ecommerce-paypal', [] );

    $migrator = $this->app['migrator'];
    $paths    = new ReflectionProperty( $migrator, 'paths' );
    $paths->setValue( $migrator, [] );
} );

it( 'wires nothing when the satellite has been uninstalled', function (): void {
    $provider = new EcommercePayPalServiceProvider( $this->app );
    $provider->boot();

    expect( $this->app->make( SatelliteRegistry::class )->isActive( EcommercePayPalServiceProvider::PACKAGE_NAME ) )->toBeFalse()
        ->and( $this->app['view']->getFinder()->getHints()['ecommerce-paypal'] )->toBe( [] )
        ->and( $this->app['migrator']->paths() )->not->toContain( EcommercePayPalServiceProvider::migrationsPath() );
} );
