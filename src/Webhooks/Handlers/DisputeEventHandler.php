<?php

/**
 * Records PayPal disputes on their orders.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks\Handlers;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalDispute;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `CUSTOMER.DISPUTE.CREATED`, `.UPDATED`, and `.RESOLVED` (spec §8.3).
 *
 * The dispute is fetched from PayPal and matched to an order through its
 * disputed capture ids; disputes about captures no order recorded are
 * ignored. For a match it:
 *
 * - adds a system note through `OrderNoteService` with the reason, amount,
 *   respond-by date, and the Resolution Center link (translated);
 * - writes a `paypal.dispute_created` / `_updated` / `_resolved` timeline
 *   entry;
 * - keeps a summary in `orders.meta.paypal.disputes.{dispute id}`;
 * - fires `ap.ecommerce.paypal.disputeCreated` / `disputeUpdated` /
 *   `disputeResolved` `(Order $order, array $dispute)`.
 *
 * The order status isn't changed. Moving disputed orders is store policy,
 * which hosts can apply from the hooks or a kanban automation.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class DisputeEventHandler extends WebhookEventHandler
{
    /**
     * The hook fired for each event type.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const HOOKS = [
        'CUSTOMER.DISPUTE.CREATED'  => 'ap.ecommerce.paypal.disputeCreated',
        'CUSTOMER.DISPUTE.UPDATED'  => 'ap.ecommerce.paypal.disputeUpdated',
        'CUSTOMER.DISPUTE.RESOLVED' => 'ap.ecommerce.paypal.disputeResolved',
    ];

    /**
     * Creates the handler.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient        $client  API client.
     * @param  PayPalOrderLocator  $orders  Order lookup.
     * @param  OrderNoteService    $notes   Order notes.
     */
    public function __construct(
        PayPalClient $client,
        PayPalOrderLocator $orders,
        protected OrderNoteService $notes,
    ) {
        parent::__construct( $client, $orders );
    }

    /**
     * Handles a dispute event.
     *
     * @since 1.0.0
     *
     * @param  string                $type      Event type.
     * @param  array<string, mixed>  $resource  Event resource (untrusted).
     * @param  string                $eventId   Event id.
     *
     * @return void
     */
    public function handle( string $type, array $resource, string $eventId ): void
    {
        $hook      = self::HOOKS[ $type ] ?? null;
        $disputeId = isset( $resource['dispute_id'] ) && is_scalar( $resource['dispute_id'] ) ? trim( (string) $resource['dispute_id'] ) : '';

        if ( null === $hook || '' === $disputeId ) {
            return;
        }

        $dispute = $this->fetch( fn (): PayPalDispute => $this->client->disputes()->get( $disputeId ), $eventId );

        if ( null === $dispute || $dispute->id() !== $disputeId ) {
            return;
        }

        $order = null;

        foreach ( $dispute->captureIds() as $captureId ) {
            $order = $this->orderForCapture( $captureId, $eventId );

            if ( null !== $order ) {
                break;
            }
        }

        if ( null === $order ) {
            Log::channel( self::logChannel() )->info( 'A PayPal dispute matched no order.', [
                'event_id'   => $eventId,
                'dispute_id' => $disputeId,
            ] );

            return;
        }

        $this->once( $order, $eventId, fn () => $this->apply( $type, $hook, $order, $dispute ) );
    }

    /**
     * A respond-by date in the app's locale and timezone, with the zone
     * shown (`Saturday, November 22, 2026 10:00 AM UTC`), or null.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable|null  $date  Date.
     *
     * @return string|null
     */
    public static function date( ?CarbonImmutable $date ): ?string
    {
        if ( null === $date ) {
            return null;
        }

        $local = $date->setTimezone( (string) config( 'app.timezone', 'UTC' ) )->locale( app()->getLocale() );

        return $local->isoFormat( 'LLLL' ) . ' ' . $local->format( 'T' );
    }

    /**
     * A translated label for a dispute reason.
     *
     * @since 1.0.0
     *
     * @param  string|null  $reason  PayPal reason code.
     *
     * @return string
     */
    public static function reasonLabel( ?string $reason ): string
    {
        return match ( $reason ) {
            'MERCHANDISE_OR_SERVICE_NOT_RECEIVED'     => __( 'item not received' ),
            'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED' => __( 'item not as described' ),
            'UNAUTHORISED'                            => __( 'unauthorized transaction' ),
            'CREDIT_NOT_PROCESSED'                    => __( 'credit not processed' ),
            'DUPLICATE_TRANSACTION'                   => __( 'duplicate transaction' ),
            'INCORRECT_AMOUNT'                        => __( 'incorrect amount' ),
            'PAYMENT_BY_OTHER_MEANS'                  => __( 'paid by other means' ),
            'CANCELED_RECURRING_BILLING'              => __( 'canceled recurring billing' ),
            'PROBLEM_WITH_REMITTANCE'                 => __( 'problem with remittance' ),
            default                                   => __( 'other' ),
        };
    }

    /**
     * A translated label for a resolved dispute's outcome.
     *
     * @since 1.0.0
     *
     * @param  string|null  $outcome  PayPal outcome code.
     *
     * @return string
     */
    public static function outcomeLabel( ?string $outcome ): string
    {
        return match ( $outcome ) {
            'RESOLVED_BUYER_FAVOUR'  => __( 'in the buyer\'s favor' ),
            'RESOLVED_SELLER_FAVOUR' => __( 'in your favor' ),
            'RESOLVED_WITH_PAYOUT'   => __( 'with a payout from PayPal' ),
            'CANCELED_BY_BUYER'      => __( 'canceled by the buyer' ),
            'ACCEPTED'               => __( 'accepted (the buyer was refunded)' ),
            'DENIED'                 => __( 'denied' ),
            default                  => __( 'closed' ),
        };
    }

    /**
     * The event's side effects: summary, timeline entry, note, and hook.
     *
     * @since 1.0.0
     *
     * @param  string         $type     Event type.
     * @param  string         $hook     Hook to fire.
     * @param  Order          $order    Order.
     * @param  PayPalDispute  $dispute  Dispute, as PayPal returned it.
     *
     * @return void
     */
    protected function apply( string $type, string $hook, Order $order, PayPalDispute $dispute ): void
    {
        $disputeId = $dispute->id();
        $summary   = $this->summary( $dispute );
        $event     = Str::of( $type )->afterLast( '.' )->lower()->toString();

        PayPalOrderMeta::merge( $order, [ 'disputes' => [ $disputeId => $summary ] ] );

        $this->timeline( $order, 'paypal.dispute_' . $event, $summary );

        try {
            $this->notes->add( $order, $this->note( $event, $dispute, $summary ) );
        } catch ( Throwable $exception ) {
            Log::channel( self::logChannel() )->warning( 'Could not add the PayPal dispute note.', [
                'order_id'   => $order->getKey(),
                'dispute_id' => $disputeId,
                'error'      => $exception->getMessage(),
            ] );
        }

        doAction( $hook, $order, $summary );
    }

    /**
     * The summary kept on the order, passed to the hooks, and written to
     * the timeline.
     *
     * @since 1.0.0
     *
     * @param  PayPalDispute  $dispute  Dispute.
     *
     * @return array{id: string, reason: string|null, status: string|null, stage: string|null, outcome: string|null, amount: string|null, currency: string|null, respond_by: string|null, capture_ids: array<int, string>, url: string, updated_at: string}
     */
    protected function summary( PayPalDispute $dispute ): array
    {
        return [
            'id'          => $dispute->id(),
            'reason'      => $dispute->reason(),
            'status'      => $dispute->status(),
            'stage'       => $dispute->stage(),
            'outcome'     => $dispute->outcome(),
            'amount'      => $dispute->amount()['value'] ?? null,
            'currency'    => $dispute->amount()['currency_code'] ?? null,
            'respond_by'  => $dispute->respondBy()?->toIso8601String(),
            'capture_ids' => $dispute->captureIds(),
            'url'         => $this->client->environment()->disputeUrl( $dispute->id() ),
            'updated_at'  => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * The note body for an event.
     *
     * @since 1.0.0
     *
     * @param  string                $event    `created`, `updated`, or `resolved`.
     * @param  PayPalDispute         $dispute  Dispute.
     * @param  array<string, mixed>  $summary  Summary.
     *
     * @return string
     */
    protected function note( string $event, PayPalDispute $dispute, array $summary ): string
    {
        $values = array_filter( [
            'id'      => $dispute->id(),
            'reason'  => self::reasonLabel( $dispute->reason() ),
            'amount'  => null === $summary['amount'] ? __( 'amount unknown' ) : $summary['amount'] . ' ' . $summary['currency'],
            'status'  => Str::of( (string) ( $dispute->status() ?? '' ) )->lower()->replace( '_', ' ' )->toString(),
            'outcome' => self::outcomeLabel( $dispute->outcome() ),
            'date'    => self::date( $dispute->respondBy() ),
            'url'     => $summary['url'],
        ], static fn ( ?string $value ): bool => null !== $value );

        if ( 'resolved' === $event ) {
            return __( 'PayPal dispute :id was resolved :outcome. Resolution Center: :url', $values );
        }

        if ( 'created' === $event ) {
            return ! isset( $values['date'] )
                ? __( 'PayPal dispute :id opened: :reason, :amount. Resolution Center: :url', $values )
                : __( 'PayPal dispute :id opened: :reason, :amount. Respond by :date. Resolution Center: :url', $values );
        }

        return ! isset( $values['date'] )
            ? __( 'PayPal dispute :id updated: :status (:reason, :amount). Resolution Center: :url', $values )
            : __( 'PayPal dispute :id updated: :status (:reason, :amount). Respond by :date. Resolution Center: :url', $values );
    }
}
