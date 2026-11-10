<?php

/**
 * Maps PayPal errors to payment and refund failures.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Support;

use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use Money\Money;

/**
 * Turns a {@see PayPalApiException} into the engine result a capture or
 * refund returns (spec §7.3, §7.5): declines never throw.
 *
 * Codes are PayPal's issue, lower-cased (`instrument_declined`,
 * `refund_amount_exceeded`), so the timeline and logs say exactly what
 * PayPal refused. Messages are translated and never include PayPal's own
 * text, which can carry internal detail.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalDeclines
{
    /**
     * The code for a call that got no usable answer (timeout, `429`, `5xx`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * The failed {@see PaymentResult} for a capture or authorize call.
     *
     * - `INSTRUMENT_DECLINED` is retryable: the buttons restart so the
     *   shopper can pick another funding source.
     * - `PAYER_ACTION_REQUIRED` is retryable with code `requires_action`.
     * - Timeouts, `429`s, and `5xx`s are retryable.
     * - Everything else (`422`s, a missing Order) is terminal.
     *
     * @since 1.0.0
     *
     * @param  PayPalApiException  $exception  Failure.
     * @param  Money               $amount     Amount attempted.
     *
     * @return PaymentResult
     */
    public static function payment( PayPalApiException $exception, Money $amount ): PaymentResult
    {
        if ( $exception->hasIssue( 'INSTRUMENT_DECLINED' ) ) {
            return PaymentResult::retryableFailure(
                $amount,
                'instrument_declined',
                __( 'Your payment method was declined. Choose another way to pay.' ),
            );
        }

        if ( $exception->hasIssue( 'PAYER_ACTION_REQUIRED' ) ) {
            return PaymentResult::retryableFailure(
                $amount,
                'requires_action',
                __( 'PayPal needs you to complete another step before this payment can be taken.' ),
            );
        }

        if ( $exception->isRetryable() ) {
            return PaymentResult::retryableFailure(
                $amount,
                self::PROVIDER_UNAVAILABLE,
                __( 'PayPal could not be reached. Try again in a moment.' ),
            );
        }

        if ( 404 === $exception->status ) {
            return PaymentResult::terminalFailure(
                $amount,
                'paypal_order_not_found',
                __( 'PayPal has no record of this payment. Start the payment again.' ),
            );
        }

        return PaymentResult::terminalFailure(
            $amount,
            self::code( $exception, 'payment_declined' ),
            __( 'PayPal declined the payment. Choose another way to pay.' ),
        );
    }

    /**
     * The failed {@see RefundResult} for a refund call.
     *
     * @since 1.0.0
     *
     * @param  PayPalApiException  $exception  Failure.
     * @param  Money               $amount     Amount attempted.
     *
     * @return RefundResult
     */
    public static function refund( PayPalApiException $exception, Money $amount ): RefundResult
    {
        if ( $exception->isRetryable() ) {
            return RefundResult::failure(
                $amount,
                self::PROVIDER_UNAVAILABLE,
                __( 'PayPal could not be reached. Try the refund again in a moment.' ),
            );
        }

        $code = self::code( $exception, 'refund_declined' );

        return RefundResult::failure( $amount, $code, self::refundMessage( $code ) );
    }

    /**
     * The translated message for a refund decline code.
     *
     * @since 1.0.0
     *
     * @param  string  $code  Lower-cased PayPal issue.
     *
     * @return string
     */
    public static function refundMessage( string $code ): string
    {
        return match ( $code ) {
            'capture_fully_refunded'          => __( 'This payment has already been fully refunded.' ),
            'refund_amount_exceeded'          => __( 'The refund is more than what is left to refund on this payment.' ),
            'refund_time_limit_exceeded'      => __( 'This payment is too old for PayPal to refund.' ),
            'partial_refund_not_allowed',
            'capture_disputed_partial_refund_not_allowed' => __( 'PayPal only allows a full refund on this payment.' ),
            'max_number_of_refunds_exceeded'              => __( 'This payment has reached the number of refunds PayPal allows.' ),
            'refund_failed_insufficient_funds'            => __( 'Your PayPal balance does not cover this refund.' ),
            'refund_not_allowed',
            'refund_not_permitted_due_to_chargeback',
            'transaction_refused'             => __( 'PayPal does not allow a refund on this payment.' ),
            'resource_not_found',
            'invalid_resource_id'             => __( 'PayPal has no record of the payment being refunded.' ),
            default                           => __( 'PayPal declined the refund.' ),
        };
    }

    /**
     * PayPal's issue (or error name), lower-cased, or `$fallback`.
     *
     * @since 1.0.0
     *
     * @param  PayPalApiException  $exception  Failure.
     * @param  string              $fallback   Code when PayPal named nothing.
     *
     * @return string
     */
    private static function code( PayPalApiException $exception, string $fallback ): string
    {
        $code = $exception->issue() ?? $exception->name;

        if ( null === $code || '' === $code || 'UNPROCESSABLE_ENTITY' === $code ) {
            return $fallback;
        }

        return strtolower( $code );
    }
}
