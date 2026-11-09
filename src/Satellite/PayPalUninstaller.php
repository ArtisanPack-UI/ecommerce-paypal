<?php

/**
 * PayPal satellite uninstaller.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Satellite;

use ArtisanPackUI\Ecommerce\Contracts\SatelliteUninstaller;
use ArtisanPackUI\Ecommerce\Satellites\SatelliteDescriptor;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Support\Facades\Log;

/**
 * Tears down the PayPal satellite for `ecommerce:satellite:uninstall`.
 *
 * Data is kept by default, so reinstalling picks up the stored PayPal
 * customers and vault tokens. With `--purge` the engine rolls back the
 * package's migrations (dropping both vault tables) after this runs; before
 * that, and after the engine's confirmation prompt, the stored vault tokens
 * are deleted at PayPal (spec §10.1).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalUninstaller implements SatelliteUninstaller
{
    /**
     * Runs the uninstall.
     *
     * @since 1.0.0
     *
     * @param  SatelliteDescriptor  $satellite  The satellite being uninstalled.
     * @param  bool                 $purge      Whether the tables will be dropped.
     *
     * @return void
     */
    public function uninstall( SatelliteDescriptor $satellite, bool $purge ): void
    {
        if ( ! $purge ) {
            return;
        }

        $this->deleteVaultTokens();
    }

    /**
     * Deletes the stored vault tokens at PayPal before the tables are dropped.
     *
     * Only the current mode's tokens can be deleted with the configured
     * credentials. A token PayPal refuses to delete is reported and the
     * uninstall goes on; the counts are logged.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function deleteVaultTokens(): void
    {
        $result = app( PayPalVault::class )->purge();

        if ( 0 === array_sum( $result ) ) {
            return;
        }

        Log::channel( config( 'artisanpack.ecommerce-paypal.logging.channel' ) )->info( 'PayPal vault tokens deleted before the purge.', $result );
    }
}
