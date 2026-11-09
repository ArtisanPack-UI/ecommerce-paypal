<?php

/**
 * Reauthorize command.
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

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Payments\AuthorizationLifecycle;
use Illuminate\Console\Command;

/**
 * Reauthorizes uncaptured PayPal authorizations past their 3-day honor
 * period and warns about ones about to expire (spec §7.4). Scheduled daily
 * by the service provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class ReauthorizeAuthorizationsCommand extends Command
{
    /**
     * The command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce-paypal:authorizations:reauthorize';

    /**
     * The command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Reauthorize uncaptured PayPal authorizations after their honor period and flag ones about to expire.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  AuthorizationLifecycle  $lifecycle  Authorization lifecycle.
     *
     * @return int
     */
    public function handle( AuthorizationLifecycle $lifecycle ): int
    {
        if ( ! EcommercePayPalServiceProvider::gatewaysActive() ) {
            $this->components->info( __( 'PayPal is not enabled; there are no authorizations to check.' ) );

            return self::SUCCESS;
        }

        $totals = $lifecycle->run();

        $this->components->info( __( 'Checked :checked PayPal authorizations: :reauthorized reauthorized, :expiring expiring soon, :failed failed.', $totals ) );

        return 0 === $totals['failed'] ? self::SUCCESS : self::FAILURE;
    }
}
