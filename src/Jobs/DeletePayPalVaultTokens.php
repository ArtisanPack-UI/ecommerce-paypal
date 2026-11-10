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

use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Support\PayPalRedactor;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes payment tokens from PayPal's vault after their local rows were
 * erased (a customer deletion, spec §10.1).
 *
 * Every token is tried: a token PayPal no longer has counts as deleted, and
 * one PayPal refuses for good is logged and skipped. Tokens that failed
 * because PayPal was unreachable are sent again by a new job, up to
 * {@see self::MAX_ROUNDS} rounds, before the job gives up and logs them.
 *
 * Tokens of a mode other than the current one can't be deleted: the
 * credentials that could delete them aren't configured, so they are logged.
 * The payload is encrypted, because it holds PayPal token ids.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class DeletePayPalVaultTokens implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * How many rounds unreachable-PayPal failures are retried for.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const MAX_ROUNDS = 5;

    /**
     * Minutes between rounds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const ROUND_DELAY_MINUTES = 10;

    /**
     * Attempts, for a failure outside the PayPal calls.
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
     * @param  int                 $round     Which round this is (1 to {@see self::MAX_ROUNDS}).
     */
    public function __construct( public readonly array $tokenIds, public readonly string $mode, public readonly int $round = 1 )
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
            Log::channel( self::logChannel() )->warning( 'Saved-payment tokens were not deleted at PayPal: they belong to another PayPal mode than the one configured. Delete them in that mode\'s PayPal account.', [
                'mode'         => $this->mode,
                'current_mode' => $vault->mode(),
                'count'        => count( $this->tokenIds ),
            ] );

            return;
        }

        $retryable = [];
        $last      = null;

        foreach ( $this->tokenIds as $tokenId ) {
            try {
                $vault->deleteAtPayPal( [ $tokenId ] );
            } catch ( PayPalApiException $exception ) {
                if ( $exception->isRetryable() ) {
                    $retryable[] = $tokenId;
                    $last        = $exception;

                    continue;
                }

                Log::channel( self::logChannel() )->error( 'PayPal refused to delete a saved-payment token.', [
                    'token_id' => PayPalRedactor::MASK,
                    'mode'     => $this->mode,
                ] + $exception->context() );
            }
        }

        if ( [] === $retryable ) {
            return;
        }

        if ( $this->round >= self::MAX_ROUNDS ) {
            $this->logGaveUp( count( $retryable ), $last );

            return;
        }

        self::dispatch( $retryable, $this->mode, $this->round + 1 )->delay( now()->addMinutes( self::ROUND_DELAY_MINUTES ) );
    }

    /**
     * Logs a job that gave up, with how many tokens may still be at PayPal.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $exception  The last failure.
     *
     * @return void
     */
    public function failed( Throwable $exception ): void
    {
        $this->logGaveUp( count( $this->tokenIds ), $exception );
    }

    /**
     * Logs, at `critical`, that `$count` tokens may still be at PayPal.
     *
     * @since 1.0.0
     *
     * @param  int             $count      Tokens not deleted.
     * @param  Throwable|null  $exception  The last failure.
     *
     * @return void
     */
    protected function logGaveUp( int $count, ?Throwable $exception ): void
    {
        Log::channel( self::logChannel() )->critical( 'Gave up deleting saved-payment tokens at PayPal. Delete them in the PayPal account.', array_filter( [
            'mode'     => $this->mode,
            'count'    => $count,
            'debug_id' => $exception instanceof PayPalApiException ? $exception->debugId : null,
            'error'    => null === $exception ? null : PayPalRedactor::redactPath( $exception->getMessage() ),
        ], static fn ( mixed $value ): bool => null !== $value && '' !== $value ) );
    }

    /**
     * The configured log channel, or null for the default.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    private static function logChannel(): ?string
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return is_string( $channel ) && '' !== $channel ? $channel : null;
    }
}
