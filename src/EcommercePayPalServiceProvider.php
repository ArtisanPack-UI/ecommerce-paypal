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

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Registries\SatelliteRegistry;
use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Api\PayPalCredentials;
use ArtisanPackUI\EcommercePayPal\Api\PayPalTokenProvider;
use ArtisanPackUI\EcommercePayPal\Console\Commands\InstallCommand;
use ArtisanPackUI\EcommercePayPal\Console\Commands\ReauthorizeAuthorizationsCommand;
use ArtisanPackUI\EcommercePayPal\Console\Commands\RegisterWebhookCommand;
use ArtisanPackUI\EcommercePayPal\Console\Commands\VerifyCredentialsCommand;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalApplePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGooglePayGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Jobs\ProcessPayPalWebhook;
use ArtisanPackUI\EcommercePayPal\Livewire\PayLaterMessage as PayLaterMessageComponent;
use ArtisanPackUI\EcommercePayPal\Livewire\Payment\PayPalButtons;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Satellite\PayPalUninstaller;
use ArtisanPackUI\EcommercePayPal\Settings\PayPalSettings;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor;
use ArtisanPackUI\EcommerceStorefrontLivewire\Registries\PaymentDriverRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
     * The gateway family (spec §6.1), in checkout order.
     *
     * @since 1.0.0
     *
     * @var array<int, class-string<PayPalGateway>>
     */
    public const GATEWAYS = [
        PayPalWalletGateway::class,
        PayPalCardGateway::class,
        PayPalApplePayGateway::class,
        PayPalGooglePayGateway::class,
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

        $this->app->singleton( PayPalTokenProvider::class, static fn ( Application $app ): PayPalTokenProvider => new PayPalTokenProvider(
            $app->make( HttpFactory::class ),
            $app->make( 'cache' )->store(),
        ) );

        // No fixed credentials: the client reads config on every call, so a
        // settings change (mode, client id) applies straight away.
        $this->app->singleton( PayPalClient::class, static fn ( Application $app ): PayPalClient => new PayPalClient(
            $app->make( HttpFactory::class ),
            $app->make( PayPalTokenProvider::class ),
        ) );

        $this->app->singleton( OrderPayloadBuilder::class );
        $this->app->singleton( PayPalOrderLocator::class );
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
        $this->registerCommands();

        // Settings first: defining them applies the admin's stored values
        // to config, which decides whether the gateways register.
        $this->registerSettings();
        $this->registerGateways();
        $this->registerStorefrontDrivers();
    }

    /**
     * Whether the gateways should be registered: the package is enabled and
     * both credentials are set (spec §6.2).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function gatewaysActive(): bool
    {
        return (bool) config( self::CONFIG_KEY . '.enabled', false ) && PayPalCredentials::configured();
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
     * Loads the package views and Blade components under the
     * `ecommerce-paypal` namespace.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerViews(): void
    {
        $this->loadViewsFrom( dirname( __DIR__ ) . '/resources/views', self::VIEW_NAMESPACE );

        // `<x-ecommerce-paypal::pay-later-message />` (spec §9.7).
        Blade::componentNamespace( 'ArtisanPackUI\\EcommercePayPal\\View\\Components', self::VIEW_NAMESPACE );
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

    /**
     * Registers the setup commands and schedules the daily
     * reauthorization run.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ( ! $this->app->runningInConsole() ) {
            return;
        }

        $this->commands( [
            InstallCommand::class,
            VerifyCredentialsCommand::class,
            RegisterWebhookCommand::class,
            ReauthorizeAuthorizationsCommand::class,
        ] );

        // Spec §7.4: authorizations past their honor period are
        // reauthorized once a day. The command does nothing while PayPal
        // is switched off.
        $this->callAfterResolving( Schedule::class, static function ( Schedule $schedule ): void {
            $schedule->command( ReauthorizeAuthorizationsCommand::class )
                ->daily()
                ->withoutOverlapping();
        } );
    }

    /**
     * Adds the `paypal` settings group.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerSettings(): void
    {
        PayPalSettings::register(
            $this->app->make( SettingsRegistry::class ),
            $this->app->make( SettingsRepository::class ),
        );
    }

    /**
     * Registers the Livewire components: the live Pay Later message when
     * Livewire is installed (spec §9.7), and the payment drivers with
     * storefront-livewire's `PaymentDriverRegistry` when that package is
     * installed (spec §9.2).
     *
     * A driver the registry already holds is left alone, so a host's
     * `payments.drivers` config wins whichever provider boots first (the
     * storefront applies its config after its own drivers).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerStorefrontDrivers(): void
    {
        if ( ! class_exists( Livewire::class ) ) {
            return;
        }

        // The live Pay Later message only needs Livewire.
        Livewire::component( PayLaterMessageComponent::COMPONENT, PayLaterMessageComponent::class );

        if ( ! class_exists( PaymentDriverRegistry::class ) ) {
            return;
        }

        Livewire::component( PayPalButtons::COMPONENT, PayPalButtons::class );

        $registry = $this->app->make( PaymentDriverRegistry::class );

        if ( ! $registry->has( PayPalButtons::DRIVER ) ) {
            $registry->register( PayPalButtons::DRIVER, PayPalButtons::COMPONENT );
        }
    }

    /**
     * Registers the four gateways, the webhook event processor, and the
     * per-cart availability filter.
     *
     * Every gateway in the family is registered while the package is
     * active, even a method that is switched off, so orders placed through
     * it can still be refunded and reconciled. The availability filter keeps
     * switched-off methods out of checkout.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function registerGateways(): void
    {
        if ( ! self::gatewaysActive() ) {
            return;
        }

        $registry = $this->app->make( PaymentGatewayRegistry::class );

        foreach ( self::GATEWAYS as $gateway ) {
            $registry->register( $gateway::KEY, $gateway );
        }

        // Notes, refunds, and disputes from PayPal's events. The engine fires
        // this action only for verified deliveries it hasn't seen before.
        addAction( 'ap.ecommerce.gateway.' . PayPalWalletGateway::KEY . '.webhook_received', static function ( mixed $result ): void {
            if ( $result instanceof WebhookResult && $result->verified && PayPalWebhookProcessor::handles( $result->eventType ) ) {
                ProcessPayPalWebhook::dispatch( PayPalWebhookProcessor::minimal( $result->payload ) );
            }
        } );

        addFilter( 'ap.ecommerce.payment.availableGateways', static function ( mixed $gateways, mixed $cart = null ): mixed {
            if ( ! is_array( $gateways ) || ! $cart instanceof Cart ) {
                return $gateways;
            }

            return array_filter(
                $gateways,
                static fn ( mixed $gateway ): bool => ! $gateway instanceof PayPalGateway || $gateway->isAvailableFor( $cart ),
            );
        }, 10, 2 );
    }
}
