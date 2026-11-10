<?php

declare( strict_types=1 );

namespace Tests;

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Base case for the browser suite (tests/Browser).
 *
 * Serves the Testbench app from `tests/Browser/workbench/public`, where
 * `npm run build:browser` writes the stylesheet, loads that stylesheet in
 * the storefront's layout, and copies the shared checkout module
 * (`dist/paypal-checkout.js`) to where `ecommerce-paypal:install` would
 * publish it, so the drivers load the module the package ships.
 *
 * @since 1.0.0
 */
abstract class BrowserTestCase extends TestCase
{
    /**
     * The workbench entries the storefront layout loads.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VITE_ENTRIES = [
        'tests/Browser/workbench/resources/css/app.css',
    ];

    /**
     * Sets up the harness.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ( ! is_file( public_path( 'build/manifest.json' ) ) ) {
            throw new RuntimeException( 'Build the browser stylesheet first: npm ci && npm run build:browser' );
        }

        $module = public_path( 'vendor/' . EcommercePayPalServiceProvider::VIEW_NAMESPACE . '/paypal-checkout.js' );

        File::ensureDirectoryExists( dirname( $module ) );
        File::copy( dirname( __DIR__ ) . '/dist/paypal-checkout.js', $module );

        addFilter( 'ap.ecommerceStorefrontLivewire.layout.viteEntries', static fn (): array => self::VITE_ENTRIES );
    }

    /**
     * Tears down the harness.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function tearDown(): void
    {
        removeAllFilters( 'ap.ecommerceStorefrontLivewire.layout.viteEntries' );

        parent::tearDown();
    }

    /**
     * Defines environment setup.
     *
     * @since 1.0.0
     *
     * @param  \Illuminate\Foundation\Application  $app  The application instance.
     *
     * @return void
     */
    protected function defineEnvironment( $app ): void
    {
        parent::defineEnvironment( $app );

        $app->usePublicPath( __DIR__ . '/Browser/workbench/public' );

        $app['config']->set( 'app.name', 'PayPal Test Store' );
        $app['config']->set( 'artisanpack.ecommerce.base_currency', 'USD' );
    }
}
