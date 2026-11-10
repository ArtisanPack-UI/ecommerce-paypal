<?php

/**
 * PayPal Vault v3 payment token page.
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
 * One page of a customer's saved payment methods.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalPaymentTokenList extends PayPalData
{
    /**
     * The tokens on this page.
     *
     * @since 1.0.0
     *
     * @return array<int, PayPalPaymentToken>
     */
    public function tokens(): array
    {
        return array_map(
            static fn ( array $token ): PayPalPaymentToken => PayPalPaymentToken::fromArray( $token ),
            array_values( array_filter( (array) ( $this->raw['payment_tokens'] ?? [] ), 'is_array' ) ),
        );
    }

    /**
     * The total number of tokens across pages.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function totalItems(): int
    {
        return (int) ( $this->raw['total_items'] ?? count( $this->tokens() ) );
    }

    /**
     * The total number of pages.
     *
     * @since 1.0.0
     *
     * @return int
     */
    public function totalPages(): int
    {
        return (int) ( $this->raw['total_pages'] ?? 1 );
    }
}
