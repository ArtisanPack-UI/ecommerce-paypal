<?php

/**
 * Queued processing of a PayPal webhook event.
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

use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs {@see PayPalWebhookProcessor} off the webhook request, so PayPal gets
 * its 200 straight away, and retries when PayPal's API is briefly
 * unreachable.
 *
 * It uses the engine's webhook queue (`artisanpack.ecommerce.webhooks.connection`
 * and `.queue`) and is dispatched after the delivery's ledger row commits.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class ProcessPayPalWebhook implements ShouldQueue
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
     * @param  array<string, mixed>  $event  The verified event.
     */
    public function __construct( public readonly array $event )
    {
        $this->onConnection( config( 'artisanpack.ecommerce.webhooks.connection' ) );
        $this->onQueue( config( 'artisanpack.ecommerce.webhooks.queue' ) );
        $this->afterCommit();
    }

    /**
     * Processes the event.
     *
     * @since 1.0.0
     *
     * @param  PayPalWebhookProcessor  $processor  Processor.
     *
     * @return void
     */
    public function handle( PayPalWebhookProcessor $processor ): void
    {
        $processor->process( $this->event );
    }
}
