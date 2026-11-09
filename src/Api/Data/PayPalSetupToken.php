<?php

/**
 * PayPal Vault v3 setup token.
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
 * A setup token, for saving a payment method without a purchase
 * (`/v3/vault/setup-tokens`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalSetupToken extends PayPalData
{
    /**
     * The PayPal customer the token is for, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function customerId(): ?string
    {
        return $this->string( 'customer.id' );
    }

    /**
     * The link the buyer follows to approve saving, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function approveUrl(): ?string
    {
        return $this->link( 'approve' );
    }
}
