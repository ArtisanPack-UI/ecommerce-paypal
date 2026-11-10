<?php

/**
 * Prune vault command.
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

use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Cleans up saved payment methods (spec §10.1): erases the vault data of
 * customers the engine no longer has, deleting their tokens at PayPal, and
 * deletes removed methods older than `vault.prune_after_days`. Scheduled
 * daily by the service provider.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PruneVaultCommand extends Command
{
    /**
     * The command signature.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $signature = 'ecommerce-paypal:prune-vault';

    /**
     * The command description.
     *
     * @since 1.0.0
     *
     * @var string
     */
    protected $description = 'Erase saved PayPal payment methods of deleted customers and prune removed ones.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  PayPalVault  $vault  Vault.
     *
     * @return int
     */
    public function handle( PayPalVault $vault ): int
    {
        if ( ! Schema::hasTable( ( new PayPalPaymentToken() )->getTable() ) ) {
            $this->components->info( __( 'The PayPal vault tables aren\'t installed; there is nothing to prune.' ) );

            return self::SUCCESS;
        }

        $orphans = $vault->forgetOrphans();
        $pruned  = ( new PayPalPaymentToken() )->pruneAll();

        $this->components->info( __( 'Deleted customers whose saved payment methods were erased: :customers. Removed payment methods pruned: :pruned.', [
            'customers' => $orphans,
            'pruned'    => $pruned,
        ] ) );

        return self::SUCCESS;
    }
}
