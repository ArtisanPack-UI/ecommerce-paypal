<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPack\LivewireUiComponents\LivewireUiComponentsServiceProvider;
use ArtisanPackUI\Core\CoreServiceProvider;
use ArtisanPackUI\Ecommerce\Providers\EcommerceServiceProvider;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommerceStorefrontLivewire\EcommerceStorefrontLivewireServiceProvider;
use ArtisanPackUI\Hooks\Providers\HooksServiceProvider;
use ArtisanPackUI\Security\SecurityServiceProvider;
use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;
use Laravel\Scout\ScoutServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Rebing\GraphQL\GraphQLServiceProvider;

/**
 * Base Test Case
 *
 * Boots the PayPal gateway with the engine.
 *
 * @since   1.0.0
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Gets package providers.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return array<int, class-string> Array of service provider class names.
     */
    protected function getPackageProviders( $app ): array
    {
        // rebing/graphql-laravel is optional in the engine, so it's only
        // registered when it is installed.
        $graphQl = class_exists( GraphQLServiceProvider::class )
            ? [ GraphQLServiceProvider::class ]
            : [];

        // storefront-livewire is a dev dependency (the Livewire drivers);
        // it's booted when installed, as in a host app.
        $storefront = class_exists( EcommerceStorefrontLivewireServiceProvider::class )
            ? [
                LivewireServiceProvider::class,
                BladeIconsServiceProvider::class,
                BladeHeroiconsServiceProvider::class,
                LivewireUiComponentsServiceProvider::class,
                EcommerceStorefrontLivewireServiceProvider::class,
            ]
            : [];

        return [
            CoreServiceProvider::class,
            HooksServiceProvider::class,
            SecurityServiceProvider::class,
            SanctumServiceProvider::class,
            ScoutServiceProvider::class,
            ...$graphQl,
            EcommerceServiceProvider::class,
            ...$storefront,
            EcommercePayPalServiceProvider::class,
        ];
    }

    /**
     * Defines environment setup.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     */
    protected function defineEnvironment( $app ): void
    {
        $app['config']->set( 'app.key', 'base64:' . base64_encode( random_bytes( 32 ) ) );

        $app['config']->set( 'database.default', 'testbench' );
        $app['config']->set( 'database.connections.testbench', [
            'driver'                  => 'sqlite',
            'database'                => ':memory:',
            'prefix'                  => '',
            'foreign_key_constraints' => true,
        ] );
    }
}
