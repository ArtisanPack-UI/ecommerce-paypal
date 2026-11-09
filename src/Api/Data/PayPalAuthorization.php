<?php

/**
 * PayPal Payments v2 authorization.
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
 * An authorized payment (`/v2/payments/authorizations/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalAuthorization extends PayPalData
{
    /**
     * The authorized amount, or null.
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
     * When the authorization stops being valid, or null.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null
     */
    public function expiresAt(): ?CarbonImmutable
    {
        return $this->time( 'expiration_time' );
    }

    /**
     * When the authorization was created, or null.
     *
     * @since 1.0.0
     *
     * @return CarbonImmutable|null
     */
    public function createdAt(): ?CarbonImmutable
    {
        return $this->time( 'create_time' );
    }

    /**
     * The PayPal Order id this authorization belongs to, or null.
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
     * Parses a timestamp field.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Dot path.
     *
     * @return CarbonImmutable|null
     */
    private function time( string $path ): ?CarbonImmutable
    {
        $value = $this->string( $path );

        if ( null === $value ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( $value );
        } catch ( Throwable ) {
            return null;
        }
    }
}
