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
use RuntimeException;

/**
 * Tears down the PayPal satellite for `ecommerce:satellite:uninstall`.
 *
 * Data is kept by default, so reinstalling picks up the stored PayPal
 * customers and vault tokens. With `--purge` the engine rolls back the
 * package's migrations (dropping both vault tables) after this runs; before
 * that, and after the engine's confirmation prompt, the stored vault tokens
 * are deleted at PayPal (spec §10.1). If any can't be, this throws and the
 * engine drops nothing.
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
     * @throws RuntimeException When PayPal couldn't delete every token, so the engine drops nothing.
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
     * credentials; the other mode's are logged. When PayPal refuses or
     * can't be reached for any token, the uninstall stops before the engine
     * drops the tables, so the tokens can still be found and deleted on the
     * next run.
     *
     * @since 1.0.0
     *
     * @throws RuntimeException When a token couldn't be deleted at PayPal.
     *
     * @return void
     */
    protected function deleteVaultTokens(): void
    {
        $result  = app( PayPalVault::class )->purge();
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );
        $logger  = Log::channel( is_string( $channel ) && '' !== $channel ? $channel : null );

        if ( $result['failed'] > 0 ) {
            $logger->error( 'PayPal could not delete every vault token, so the purge was stopped.', $result );

            throw new RuntimeException( __( 'PayPal couldn\'t delete :count saved payment tokens, so nothing was purged. Run the uninstall again.', [ 'count' => $result['failed'] ] ) );
        }

        if ( $result['skipped'] > 0 ) {
            $logger->warning( 'Vault tokens of the other PayPal mode were not deleted at PayPal before the purge. Delete them in that mode\'s PayPal account.', $result );

            return;
        }

        if ( 0 === array_sum( $result ) ) {
            return;
        }

        $logger->info( 'PayPal vault tokens deleted before the purge.', $result );
    }
}
