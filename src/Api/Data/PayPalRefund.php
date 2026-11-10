<?php

/**
 * PayPal Payments v2 refund.
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
 * A refund (`/v2/payments/refunds/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalRefund extends PayPalData
{
    /**
     * The refunded amount, or null.
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
     * The capture this refund was made against (from the `up` link), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function captureId(): ?string
    {
        $up = $this->link( 'up' );

        if ( null === $up || 1 !== preg_match( '#/v2/payments/captures/([^/?]+)#', $up, $matches ) ) {
            return null;
        }

        return rawurldecode( $matches[1] );
    }

    /**
     * The `custom_id` (the engine refund id), or null.
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
