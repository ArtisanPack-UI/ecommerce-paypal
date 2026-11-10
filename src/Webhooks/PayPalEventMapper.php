<?php

/**
 * Maps PayPal webhook events to engine outcomes.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks;

use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use Illuminate\Support\Facades\Log;

/**
 * Turns a verified PayPal event into the engine's normalized
 * {@see WebhookResult} (spec §8.2).
 *
 * | Event | Outcome |
 * |---|---|
 * | `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.AUTHORIZATION.CREATED` | `succeeded` |
 * | `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` | `failed` |
 * | `PAYMENT.AUTHORIZATION.VOIDED` | `failed` |
 * | `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED` | `refunded`, with the refund id as `refundReference` |
 * | `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.PENDING`, disputes, vault, anything else | none |
 *
 * The session reference is the PayPal Order id: the resource's id for
 * `CHECKOUT.ORDER.*`, and `supplementary_data.related_ids.order_id` for
 * payment resources.
 *
 * A `succeeded` outcome only makes the engine run finalize, which loads the
 * Order from PayPal before capturing, so a tampered body can't place an
 * unpaid order. A `failed` outcome releases a cart without asking PayPal,
 * so the capture or authorization is fetched first and the outcome is only
 * reported when PayPal confirms it, for the Order PayPal names.
 *
 * Side effects (refunds, notes, disputes) don't happen here: they run from
 * {@see PayPalWebhookProcessor} after the engine has deduplicated the
 * delivery.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalEventMapper
{
    /**
     * Capture states a `failed` outcome is confirmed by.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const FAILED_CAPTURE_STATES = [ 'DENIED', 'DECLINED', 'FAILED' ];

    /**
     * Creates the mapper.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     */
    public function __construct( protected PayPalClient $client )
    {
    }

    /**
     * Maps a verified delivery. Unverified results are returned unchanged.
     *
     * @since 1.0.0
     *
     * @param  WebhookResult  $result  The verifier's result.
     *
     * @throws PayPalApiException When PayPal can't be reached to confirm a failure (PayPal redelivers).
     *
     * @return WebhookResult
     */
    public function map( WebhookResult $result ): WebhookResult
    {
        if ( ! $result->verified || null === $result->eventType || null === $result->eventId ) {
            return $result;
        }

        $type     = $result->eventType;
        $resource = is_array( $result->payload['resource'] ?? null ) ? $result->payload['resource'] : [];
        $related  = self::relatedOrderId( $resource );

        return match ( $type ) {
            'CHECKOUT.ORDER.APPROVED'                                     => $this->result( $result, null, self::string( $resource, 'id' ) ),
            'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.AUTHORIZATION.CREATED'  => $this->result( $result, WebhookResult::OUTCOME_SUCCEEDED, $related ),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'          => $this->confirmedFailure( $result, 'capture', self::string( $resource, 'id' ), $related ),
            'PAYMENT.AUTHORIZATION.VOIDED'                                => $this->confirmedFailure( $result, 'authorization', self::string( $resource, 'id' ), $related ),
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'        => $this->result( $result, WebhookResult::OUTCOME_REFUNDED, $related, self::string( $resource, 'id' ) ),
            default                                                       => $this->result( $result, null, $related ),
        };
    }

    /**
     * The PayPal Order id a payment resource belongs to, or null.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $resource  Event resource.
     *
     * @return string|null
     */
    public static function relatedOrderId( array $resource ): ?string
    {
        return self::string( $resource, 'supplementary_data.related_ids.order_id' );
    }

    /**
     * A `failed` outcome, when PayPal confirms the capture or authorization
     * failed; no outcome otherwise.
     *
     * @since 1.0.0
     *
     * @param  WebhookResult  $result      Verified result.
     * @param  string         $kind        `capture` or `authorization`.
     * @param  string|null    $id          Capture or authorization id from the body.
     * @param  string|null    $related     PayPal Order id from the body.
     *
     * @throws PayPalApiException When PayPal can't be reached.
     *
     * @return WebhookResult
     */
    protected function confirmedFailure( WebhookResult $result, string $kind, ?string $id, ?string $related ): WebhookResult
    {
        if ( null === $id ) {
            return $this->result( $result, null, $related );
        }

        try {
            $current = 'capture' === $kind
                ? $this->client->payments()->getCapture( $id )
                : $this->client->payments()->getAuthorization( $id );
        } catch ( PayPalApiException $exception ) {
            if ( $exception->isRetryable() ) {
                Log::channel( self::logChannel() )->warning( 'Could not confirm a PayPal payment failure; PayPal will redeliver the webhook.', [
                    'event_id' => $result->eventId,
                    'type'     => $result->eventType,
                    ...$exception->context(),
                ] );

                throw $exception;
            }

            return $this->result( $result, null, $related );
        }

        $failed = 'capture' === $kind
            ? in_array( $current->status(), self::FAILED_CAPTURE_STATES, true )
            : 'VOIDED' === $current->status();

        $orderId = self::string( $current->raw, 'supplementary_data.related_ids.order_id' );

        return $this->result( $result, $failed ? WebhookResult::OUTCOME_FAILED : null, $failed ? $orderId : ( $orderId ?? $related ) );
    }

    /**
     * `$result` with an outcome and references.
     *
     * @since 1.0.0
     *
     * @param  WebhookResult  $result            Verified result.
     * @param  string|null    $outcome           Outcome.
     * @param  string|null    $sessionReference  PayPal Order id.
     * @param  string|null    $refundReference   Refund id.
     *
     * @return WebhookResult
     */
    protected function result( WebhookResult $result, ?string $outcome, ?string $sessionReference, ?string $refundReference = null ): WebhookResult
    {
        return WebhookResult::verified(
            (string) $result->eventType,
            (string) $result->eventId,
            $result->payload,
            // The engine needs a session for a payment outcome. A refund needs
            // none: refund resources carry no Order id, and the refund is
            // recorded by its own handler from `refundReference`.
            null === $sessionReference && WebhookResult::OUTCOME_REFUNDED !== $outcome ? null : $outcome,
            $sessionReference,
            $refundReference,
        );
    }

    /**
     * A non-empty string at `$path` in `$values`, or null.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values  Values.
     * @param  string                $path    Dot path.
     *
     * @return string|null
     */
    protected static function string( array $values, string $path ): ?string
    {
        $value = data_get( $values, $path );

        return is_scalar( $value ) && '' !== trim( (string) $value ) ? (string) $value : null;
    }

    /**
     * The configured log channel, or null for the default.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected static function logChannel(): ?string
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return is_string( $channel ) && '' !== $channel ? $channel : null;
    }
}
