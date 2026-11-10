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

use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookProcessor;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs {@see PayPalWebhookProcessor} off the webhook request, so PayPal gets
 * its 200 straight away, and retries when PayPal's API is briefly
 * unreachable.
 *
 * It uses the engine's webhook queue (`artisanpack.ecommerce.webhooks.connection`
 * and `.queue`) and is dispatched after the delivery's ledger row commits.
 * It retries for up to 24 hours (backing off to an hour between attempts),
 * which covers a PayPal outage the event's redeliveries would otherwise
 * have to. A job that gives up is logged at `error` on the package's log
 * channel with the event id and type, the resource id, and PayPal's debug
 * id.
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
     * Seconds between attempts (the last one repeats until
     * {@see self::retryUntil()}).
     *
     * @since 1.0.0
     *
     * @var array<int, int>
     */
    public array $backoff = [ 30, 120, 600, 1800, 3600 ];

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

    /**
     * When the job stops retrying: 24 hours after it was queued.
     *
     * @since 1.0.0
     *
     * @return DateTimeInterface
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours( 24 );
    }

    /**
     * Logs a job that gave up, so staff can find the event in PayPal.
     *
     * @since 1.0.0
     *
     * @param  Throwable  $exception  The last failure.
     *
     * @return void
     */
    public function failed( Throwable $exception ): void
    {
        $channel  = config( 'artisanpack.ecommerce-paypal.logging.channel' );
        $resource = is_array( $this->event['resource'] ?? null ) ? $this->event['resource'] : [];
        $resource = $resource['id'] ?? $resource['dispute_id'] ?? null;

        Log::channel( is_string( $channel ) && '' !== $channel ? $channel : null )->error( 'Gave up processing a PayPal webhook event.', array_filter( [
            'event_id'    => isset( $this->event['id'] ) && is_scalar( $this->event['id'] ) ? (string) $this->event['id'] : null,
            'event_type'  => isset( $this->event['event_type'] ) && is_string( $this->event['event_type'] ) ? $this->event['event_type'] : null,
            'resource_id' => is_scalar( $resource ) ? (string) $resource : null,
            'debug_id'    => $exception instanceof PayPalApiException ? $exception->debugId : null,
            'error'       => $exception->getMessage(),
        ], static fn ( ?string $value ): bool => null !== $value && '' !== $value ) );
    }
}
