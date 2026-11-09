<?php

/**
 * PayPal Vault v3 payment token.
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
 * A saved payment method (`/v3/vault/payment-tokens/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalPaymentToken extends PayPalData
{
    /**
     * The PayPal customer the token belongs to, or null.
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
     * The payment source type (`card`, `paypal`, `venmo`), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function sourceType(): ?string
    {
        $source = $this->get( 'payment_source' );

        return is_array( $source ) && [] !== $source ? (string) array_key_first( $source ) : null;
    }

    /**
     * The payment source details (brand, last digits, expiry, email, …).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function source(): array
    {
        $type = $this->sourceType();

        return null === $type ? [] : (array) $this->get( 'payment_source.' . $type, [] );
    }
}
