<?php

/**
 * Ecommerce PayPal gateway service provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal;

use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;
use Illuminate\Support\ServiceProvider;

/**
 * Bootstraps the PayPal gateway satellite.
 *
 * Registers the package with the engine's `SatelliteRegistry` first. When the
 * satellite has been uninstalled (`register()` returns false) nothing else is
 * wired: no views, translations, migrations, gateways, routes, or hooks.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class EcommercePayPalServiceProvider extends ServiceProvider
{
    /**
     * The package version reported to the engine's `SatelliteRegistry`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * The Composer package name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PACKAGE_NAME = 'artisanpack-ui/ecommerce-paypal';

    /**
     * The view and translation namespace.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VIEW_NAMESPACE = 'ecommerce-paypal';

    /**
     * The config key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_KEY = 'artisanpack.ecommerce-paypal';

    /**
     * The tables the package owns (spec §10.1).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TABLES = [
        'ecommerce_paypal_customers',
        'ecommerce_paypal_payment_tokens',
    ];

    /**
     * Registers the package configuration.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom( self::configPath(), self::CONFIG_KEY );
    }

    /**
     * Registers the satellite and, when it is active, wires the package.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function boot(): void
    {
        if ( ! $this->registerSatellite() ) {
            return;
        }

        $this->registerPublishing();
        $this->registerViews();
        $this->registerTranslations();
        $this->registerMigrations();
    }

    /**
     * The descriptor this package registers with the engine (spec §3).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public static function satelliteDescriptor(): array
    {
        return [
            'package_name'    => self::PACKAGE_NAME,
            'version'         => self::VERSION,
            'label'           => __( 'PayPal' ),
            'migration_paths' => [ self::migrationsPath() ],
            'config_keys'     => [ self::CONFIG_KEY ],
            'meta_namespaces' => [ 'orders.meta.paypal', 'cart.meta.paypal' ],
            'tables'          => self::TABLES,
            'columns'         => [],
            'product_types'   => [],
            'uninstaller'     => PayPalUninstaller::class,
        ];
    }

    /**
     * The directory holding only this package's migrations.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function migrationsPath(): string
    {
        return dirname( __DIR__ ) . '/database/migrations/ecommerce-paypal';
    }

    /**
     * The package config file.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function configPath(): string
    {
        return dirname( __DIR__ ) . '/config/artisanpack/ecommerce-paypal.php';
    }

    /**
     * Registers the package with the engine's `SatelliteRegistry`.
     *
     * @since 1.0.0
     *
     * @return bool True when the satellite is active.
     */
    protected function registerSatellite(): bool
    {
        return $this->app->make( SatelliteRegistry::class )->register( self::satelliteDescriptor() );
    }

    /**
     * Registers the config, views, assets, migrations, and translations
     * publish tags.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerPublishing(): void
    {
        $root = dirname( __DIR__ );

        $this->publishes(
            [ self::configPath() => config_path( 'artisanpack/ecommerce-paypal.php' ) ],
            'ecommerce-paypal-config',
        );

        $this->publishes(
            [ $root . '/resources/views' => resource_path( 'views/vendor/' . self::VIEW_NAMESPACE ) ],
            'ecommerce-paypal-views',
        );

        $this->publishes(
            [ $root . '/dist' => public_path( 'vendor/' . self::VIEW_NAMESPACE ) ],
            'ecommerce-paypal-assets',
        );

        $this->publishes(
            [ self::migrationsPath() => database_path( 'migrations/ecommerce-paypal' ) ],
            'ecommerce-paypal-migrations',
        );

        $this->publishes(
            [ $root . '/lang' => $this->app->langPath( 'vendor/' . self::VIEW_NAMESPACE ) ],
            'ecommerce-paypal-lang',
        );
    }

    /**
     * Loads the package views under the `ecommerce-paypal` namespace.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom( dirname( __DIR__ ) . '/resources/views', self::VIEW_NAMESPACE );
    }

    /**
     * Loads the JSON translation catalogues.
     *
     * Published catalogues in `lang/vendor/ecommerce-paypal` are loaded too so
     * a host can override individual strings.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerTranslations(): void
    {
        $this->loadJsonTranslationsFrom( dirname( __DIR__ ) . '/lang' );
        $this->loadJsonTranslationsFrom( $this->app->langPath( 'vendor/' . self::VIEW_NAMESPACE ) );
    }

    /**
     * Loads the package migrations.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom( self::migrationsPath() );
    }
}
