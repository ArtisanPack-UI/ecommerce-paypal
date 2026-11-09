<?php

/**
 * PayPal Orders v2 order.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api\Data;

/**
 * An Orders v2 order (`/v2/checkout/orders/{id}`).
 *
 * The package always creates one purchase unit, so the accessors read the
 * first one.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalOrder extends PayPalData
{
    /**
     * The intent, upper-cased (`CAPTURE` or `AUTHORIZE`), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function intent(): ?string
    {
        $intent = $this->string( 'intent' );

        return null === $intent ? null : strtoupper( $intent );
    }

    /**
     * The purchase unit amount, or null.
     *
     * @since 1.0.0
     *
     * @return array{currency_code: string, value: string}|null
     */
    public function amount(): ?array
    {
        return $this->money( 'purchase_units.0.amount' );
    }

    /**
     * The purchase unit `custom_id` (the cart id), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function customId(): ?string
    {
        return $this->string( 'purchase_units.0.custom_id' );
    }

    /**
     * The purchase unit `invoice_id`, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function invoiceId(): ?string
    {
        return $this->string( 'purchase_units.0.invoice_id' );
    }

    /**
     * The link the buyer follows to approve the order, or null.
     *
     * PayPal returns `payer-action` when a payment source was given and
     * `approve` otherwise.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function payerActionUrl(): ?string
    {
        return $this->link( 'payer-action' ) ?? $this->link( 'approve' );
    }

    /**
     * The payment source type (`paypal`, `card`, `venmo`, `apple_pay`,
     * `google_pay`), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function paymentSourceType(): ?string
    {
        $source = $this->get( 'payment_source' );

        if ( ! is_array( $source ) || [] === $source ) {
            return null;
        }

        return (string) array_key_first( $source );
    }

    /**
     * The 3-D Secure liability shift (`POSSIBLE`, `NO`, `UNKNOWN`, `YES`),
     * or null when no authentication ran.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function liabilityShift(): ?string
    {
        $type = $this->paymentSourceType();

        if ( null === $type ) {
            return null;
        }

        return $this->string( 'payment_source.' . $type . '.authentication_result.liability_shift' )
            ?? $this->string( 'payment_source.' . $type . '.card.authentication_result.liability_shift' );
    }

    /**
     * The payer's PayPal email, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function payerEmail(): ?string
    {
        return $this->string( 'payment_source.paypal.email_address' ) ?? $this->string( 'payer.email_address' );
    }

    /**
     * The payer's PayPal account id, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function payerId(): ?string
    {
        return $this->string( 'payment_source.paypal.account_id' ) ?? $this->string( 'payer.payer_id' );
    }

    /**
     * The captures on the purchase unit.
     *
     * @since 1.0.0
     *
     * @return array<int, PayPalCapture>
     */
    public function captures(): array
    {
        return array_map(
            static fn ( array $capture ): PayPalCapture => PayPalCapture::fromArray( $capture ),
            array_values( array_filter( (array) $this->get( 'purchase_units.0.payments.captures', [] ), 'is_array' ) ),
        );
    }

    /**
     * The authorizations on the purchase unit.
     *
     * @since 1.0.0
     *
     * @return array<int, PayPalAuthorization>
     */
    public function authorizations(): array
    {
        return array_map(
            static fn ( array $authorization ): PayPalAuthorization => PayPalAuthorization::fromArray( $authorization ),
            array_values( array_filter( (array) $this->get( 'purchase_units.0.payments.authorizations', [] ), 'is_array' ) ),
        );
    }

    /**
     * The most recent capture, or null.
     *
     * @since 1.0.0
     *
     * @return PayPalCapture|null
     */
    public function latestCapture(): ?PayPalCapture
    {
        return self::newest( $this->captures(), [ 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' ] );
    }

    /**
     * The most recent authorization, or null.
     *
     * @since 1.0.0
     *
     * @return PayPalAuthorization|null
     */
    public function latestAuthorization(): ?PayPalAuthorization
    {
        return self::newest( $this->authorizations(), [ 'CAPTURED', 'PARTIALLY_CAPTURED', 'CREATED' ] );
    }

    /**
     * The vault result after a save-during-purchase (`id`, `status`,
     * `customer.id`), or null.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>|null
     */
    public function vault(): ?array
    {
        $type = $this->paymentSourceType();

        if ( null === $type ) {
            return null;
        }

        $vault = $this->get( 'payment_source.' . $type . '.attributes.vault' );

        return is_array( $vault ) ? $vault : null;
    }

    /**
     * The newest item (by `create_time`, then list order) whose status is
     * one of `$settled`, or the newest item when none is.
     *
     * A later pending retry doesn't hide an earlier completed capture.
     *
     * @since 1.0.0
     *
     * @template T of PayPalData
     *
     * @param  array<int, T>       $items    Captures or authorizations.
     * @param  array<int, string>  $settled  Statuses that settle the payment.
     *
     * @return T|null
     */
    private static function newest( array $items, array $settled ): ?PayPalData
    {
        if ( [] === $items ) {
            return null;
        }

        $order = array_keys( $items );

        usort( $order, static function ( int $left, int $right ) use ( $items ): int {
            $byTime = self::createdAt( $items[ $left ] ) <=> self::createdAt( $items[ $right ] );

            return 0 !== $byTime ? $byTime : $left <=> $right;
        } );

        $sorted = array_map( static fn ( int $index ): PayPalData => $items[ $index ], $order );

        foreach ( array_reverse( $sorted ) as $item ) {
            if ( in_array( $item->status(), $settled, true ) ) {
                return $item;
            }
        }

        return $sorted[ array_key_last( $sorted ) ];
    }

    /**
     * An item's `create_time` as a timestamp (0 when missing).
     *
     * @since 1.0.0
     *
     * @param  PayPalData  $item  Capture or authorization.
     *
     * @return int
     */
    private static function createdAt( PayPalData $item ): int
    {
        $time = $item->get( 'create_time' );

        return is_string( $time ) && false !== strtotime( $time ) ? (int) strtotime( $time ) : 0;
    }
}
