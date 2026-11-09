<?php

/**
 * `ecommerce-paypal:install`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publishes the config and the prebuilt JS module, offers to publish and
 * run the migrations, and prints the Tailwind and CSP lines a host needs
 * (spec §9.1, §11).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class InstallCommand extends Command
{
    /**
     * The published config file, relative to `config/`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_FILE = 'artisanpack/ecommerce-paypal.php';

    /**
     * The Tailwind `@source` lines for the driver views.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TAILWIND_SOURCES = [
        '@source "../../vendor/artisanpack-ui/ecommerce-paypal/resources/views/**/*.blade.php";',
    ];

    /**
     * Content-Security-Policy sources the PayPal JS SDK needs (spec §9.1).
     *
     * @since 1.0.0
     *
     * @var array<string, array<int, string>>
     */
    public const CSP = [
        'script-src'  => [ 'https://www.paypal.com', 'https://*.paypal.com', 'https://applepay.cdn-apple.com', 'https://pay.google.com' ],
        'frame-src'   => [ 'https://*.paypal.com', 'https://pay.google.com' ],
        'connect-src' => [ 'https://*.paypal.com' ],
        'img-src'     => [ 'https://*.paypalobjects.com' ],
    ];

    /**
     * @var string
     */
    protected $signature = 'ecommerce-paypal:install
        {--force : Overwrite a previously published config file without asking.}
        {--migrate : Publish and run the migrations without asking.}';

    /**
     * @var string
     */
    protected $description = 'Publish the PayPal gateway config and assets, and print the setup steps.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function handle(): int
    {
        $this->components->info( __( 'Installing the PayPal gateway.' ) );

        foreach ( [ 'publishConfig', 'publishAssets', 'migrations' ] as $step ) {
            $status = $this->{$step}();

            if ( self::SUCCESS !== $status ) {
                return $status;
            }
        }

        $this->printFrontEnd();
        $this->printCsp();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * Publishes the config, asking before overwriting a published copy.
     *
     * @since 1.0.0
     *
     * @return int The `vendor:publish` exit status.
     */
    protected function publishConfig(): int
    {
        $exists = File::exists( config_path( self::CONFIG_FILE ) );
        $force  = (bool) $this->option( 'force' );

        if ( $exists && ! $force ) {
            // Without a terminal, keep the host's file unless --force says otherwise.
            $force = $this->input->isInteractive() && $this->components->confirm(
                __( 'config/:file already exists. Overwrite it?', [ 'file' => self::CONFIG_FILE ] ),
                false,
            );

            if ( ! $force ) {
                $this->components->info( __( 'Kept your existing config file.' ) );

                return self::SUCCESS;
            }
        }

        return $this->call( 'vendor:publish', [ '--tag' => 'ecommerce-paypal-config', '--force' => $force ] );
    }

    /**
     * Publishes the prebuilt JS module, replacing older copies.
     *
     * @since 1.0.0
     *
     * @return int The `vendor:publish` exit status.
     */
    protected function publishAssets(): int
    {
        return $this->call( 'vendor:publish', [ '--tag' => 'ecommerce-paypal-assets', '--force' => true ] );
    }

    /**
     * Offers to publish and run the migrations.
     *
     * @since 1.0.0
     *
     * @return int The first failing nested command's exit status, or success.
     */
    protected function migrations(): int
    {
        $migrate = (bool) $this->option( 'migrate' )
            || ( $this->input->isInteractive() && $this->components->confirm( __( 'Publish and run the PayPal migrations now?' ), false ) );

        if ( ! $migrate ) {
            $this->components->info( __( 'Skipped the migrations. Run php artisan migrate when you are ready.' ) );

            return self::SUCCESS;
        }

        $status = $this->call( 'vendor:publish', [ '--tag' => 'ecommerce-paypal-migrations' ] );

        return self::SUCCESS === $status ? $this->call( 'migrate', [ '--force' => true ] ) : $status;
    }

    /**
     * Prints the Tailwind source lines.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function printFrontEnd(): void
    {
        $this->newLine();
        $this->components->info( __( 'Add these lines to your main stylesheet (e.g. resources/css/app.css):' ) );

        foreach ( self::TAILWIND_SOURCES as $source ) {
            $this->line( '    ' . $source );
        }
    }

    /**
     * Prints the Content-Security-Policy additions.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function printCsp(): void
    {
        $this->newLine();
        $this->components->info( __( 'If your site sends a Content-Security-Policy, allow these sources:' ) );

        foreach ( self::CSP as $directive => $sources ) {
            $this->line( '    ' . $directive . ' ' . implode( ' ', $sources ) );
        }
    }

    /**
     * Prints what to do next.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function printNextSteps(): void
    {
        $this->newLine();
        $this->components->info( __( 'Next steps:' ) );
        $this->components->bulletList( [
            __( 'Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET (and PAYPAL_MODE=live when going live).' ),
            __( 'Run php artisan ecommerce-paypal:webhooks:register and set PAYPAL_WEBHOOK_ID to the id it prints.' ),
            __( 'Run php artisan ecommerce-paypal:verify-credentials to check the setup.' ),
            __( 'Set PAYPAL_ENABLED=true, or enable PayPal in the store settings.' ),
        ] );
    }
}
