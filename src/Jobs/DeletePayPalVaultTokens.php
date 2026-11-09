<?php

/**
 * Deletes vault tokens at PayPal.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Jobs;

use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Deletes payment tokens from PayPal's vault after their local rows were
 * erased (a customer deletion, spec §10.1), retrying while PayPal is
 * unreachable.
 *
 * Tokens of a mode other than the current one are left alone: the
 * credentials that could delete them aren't configured.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class DeletePayPalVaultTokens implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Attempts.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public int $tries = 5;

    /**
     * Seconds between attempts.
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public array $backoff = [ 30, 120, 600, 1800 ];

    /**
     * Creates the job.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $tokenIds  PayPal payment token ids.
     * @param  string              $mode      Mode the tokens were saved in.
     */
    public function __construct( public readonly array $tokenIds, public readonly string $mode )
    {
        $this->afterCommit();
    }

    /**
     * Deletes the tokens.
     *
     * @since 1.0.0
     *
     * @param  PayPalVault  $vault  Vault.
     *
     * @return void
     */
    public function handle( PayPalVault $vault ): void
    {
        if ( $vault->mode() !== $this->mode ) {
            return;
        }

        $vault->deleteAtPayPal( $this->tokenIds );
    }
}
