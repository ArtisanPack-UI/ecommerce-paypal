<?php

/**
 * PayPal Payments v2 capture.
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
 * A captured payment (`/v2/payments/captures/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalCapture extends PayPalData
{
    /**
     * The captured amount, or null.
     *
     * @since 1.0.0
     *
     * @return array{currency_code: string, value: string}|null
     */
    public function amount(): ?array
    {
        return $this->money( 'amount' );
    }

    /**
     * Whether no further captures can be made on the authorization.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isFinalCapture(): bool
    {
        return true === $this->get( 'final_capture' );
    }

    /**
     * The PayPal Order id this capture belongs to, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function orderId(): ?string
    {
        return $this->string( 'supplementary_data.related_ids.order_id' );
    }

    /**
     * The authorization this capture settled, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function authorizationId(): ?string
    {
        return $this->string( 'supplementary_data.related_ids.authorization_id' );
    }

    /**
     * Why a `PENDING` capture is held (`ECHECK`, `PENDING_REVIEW`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function pendingReason(): ?string
    {
        return $this->string( 'status_details.reason' );
    }

    /**
     * The `custom_id`, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function customId(): ?string
    {
        return $this->string( 'custom_id' );
    }

    /**
     * The `invoice_id`, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function invoiceId(): ?string
    {
        return $this->string( 'invoice_id' );
    }
}
