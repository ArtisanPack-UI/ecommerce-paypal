<?php

/**
 * PayPal Customer Disputes v1 dispute.
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

use Carbon\CarbonImmutable;
use Throwable;

/**
 * A dispute or chargeback (`/v1/customer/disputes/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalDispute extends PayPalData
{
    /**
     * The dispute id (`dispute_id`), or an empty string.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return (string) ( $this->string( 'dispute_id' ) ?? '' );
    }

    /**
     * Why the buyer disputed the payment (`MERCHANDISE_OR_SERVICE_NOT_RECEIVED`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function reason(): ?string
    {
        return $this->string( 'reason' );
    }

    /**
     * The dispute stage (`INQUIRY`, `CHARGEBACK`, `PRE_ARBITRATION`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function stage(): ?string
    {
        return $this->string( 'dispute_life_cycle_stage' );
    }

    /**
     * The outcome of a resolved dispute (`RESOLVED_BUYER_FAVOUR`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function outcome(): ?string
    {
        return $this->string( 'dispute_outcome.outcome_code' );
    }

    /**
     * The disputed amount, or null.
     *
     * @since 1.0.0
     *
     * @return array{currency_code: string, value: string}|null
     */
    public function amount(): ?array
    {
        return $this->money( 'dispute_amount' );
    }

    /**
     * When the seller has to respond by, or null.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null
     */
    public function respondBy(): ?CarbonImmutable
    {
        $value = $this->string( 'seller_response_due_date' );

        if ( null === $value ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( $value );
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * The seller-side transaction (capture) ids the dispute is about.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function captureIds(): array
    {
        $ids = [];

        foreach ( (array) $this->get( 'disputed_transactions', [] ) as $transaction ) {
            if ( is_array( $transaction ) && isset( $transaction['seller_transaction_id'] ) && '' !== (string) $transaction['seller_transaction_id'] ) {
                $ids[] = (string) $transaction['seller_transaction_id'];
            }
        }

        return array_values( array_unique( $ids ) );
    }
}
