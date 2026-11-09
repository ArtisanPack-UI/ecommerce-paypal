<?php

declare( strict_types=1 );

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;

it( 'implements the engine uninstaller contract', function (): void {
    expect( $this->app->make( PayPalUninstaller::class ) )->toBeInstanceOf( SatelliteUninstaller::class );
} );

it( 'runs with and without a purge', function ( bool $purge ): void {
    $descriptor = SatelliteDescriptor::fromArray( EcommercePayPalServiceProvider::satelliteDescriptor() );

    expect( fn () => $this->app->make( PayPalUninstaller::class )->uninstall( $descriptor, $purge ) )
        ->not->toThrow( Throwable::class );
} )->with( [ 'keep data' => false, 'purge' => true ] );
