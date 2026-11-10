<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Ecommerce;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;

it( 'boots alongside the engine', function (): void {
    expect( $this->app->getProvider( EcommercePayPalServiceProvider::class ) )
        ->toBeInstanceOf( EcommercePayPalServiceProvider::class );
} );

it( 'merges the config under the artisanpack key', function (): void {
    expect( config( 'artisanpack.ecommerce-paypal.enabled' ) )->toBeFalse()
        ->and( config( 'artisanpack.ecommerce-paypal.mode' ) )->toBe( 'sandbox' );
} );

it( 'registers itself with the satellite registry', function (): void {
    $registry = $this->app->make( SatelliteRegistry::class );

    expect( $registry->has( EcommercePayPalServiceProvider::PACKAGE_NAME ) )->toBeTrue()
        ->and( $registry->isActive( EcommercePayPalServiceProvider::PACKAGE_NAME ) )->toBeTrue();

    $descriptor = $registry->get( EcommercePayPalServiceProvider::PACKAGE_NAME );

    expect( $descriptor->version )->toBe( EcommercePayPalServiceProvider::VERSION )
        ->and( $descriptor->label )->toBe( 'PayPal' )
        ->and( $descriptor->configKeys )->toBe( [ 'artisanpack.ecommerce-paypal' ] )
        ->and( $descriptor->metaNamespaces )->toBe( [ 'orders.meta.paypal', 'cart.meta.paypal' ] )
        ->and( $descriptor->tables )->toBe( [ 'ecommerce_paypal_customers', 'ecommerce_paypal_payment_tokens' ] )
        ->and( $descriptor->migrationPaths )->toBe( [ EcommercePayPalServiceProvider::migrationsPath() ] )
        ->and( $descriptor->uninstaller )->toBe( PayPalUninstaller::class );
} );

it( 'keeps its migrations in a dedicated directory', function (): void {
    expect( EcommercePayPalServiceProvider::migrationsPath() )
        ->toEndWith( '/database/migrations/ecommerce-paypal' )
        ->toBeDirectory()
        ->and( $this->app['migrator']->paths() )->toContain( EcommercePayPalServiceProvider::migrationsPath() );
} );

it( 'skips its migrations when the host ignores the engine\'s', function ( bool $ignored ): void {
    $container = new Container();
    $migrator  = new class() {
        /** @var array<int, string> */
        public array $paths = [];

        public function path( string $path ): void
        {
            $this->paths[] = $path;
        }
    };

    $container->instance( 'migrator', $migrator );

    try {
        if ( $ignored ) {
            Ecommerce::ignoreMigrations();
        }

        ( fn () => $this->registerMigrations() )->call( new EcommercePayPalServiceProvider( $container ) );
    } finally {
        Ecommerce::$runsMigrations = true;
    }

    expect( $migrator->paths )->toBe( $ignored ? [] : [ EcommercePayPalServiceProvider::migrationsPath() ] );
} )->with( [
    'ignored'     => [ true ],
    'not ignored' => [ false ],
] );

it( 'registers the view namespace', function (): void {
    expect( $this->app['view']->getFinder()->getHints() )->toHaveKey( 'ecommerce-paypal' );
} );

it( 'registers the publish tags', function ( string $tag ): void {
    expect( ServiceProvider::pathsToPublish( EcommercePayPalServiceProvider::class, $tag ) )->not->toBeEmpty();
} )->with( [
    'ecommerce-paypal-config',
    'ecommerce-paypal-views',
    'ecommerce-paypal-assets',
    'ecommerce-paypal-migrations',
    'ecommerce-paypal-lang',
] );
