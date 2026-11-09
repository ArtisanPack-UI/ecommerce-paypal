<?php

/**
 * PayPal Webhooks v1 event page.
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
 * One page of webhook events.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWebhookEventList extends PayPalData
{
    /**
     * The events on this page.
     *
     * @since 1.0.0
     *
     * @return array<int, PayPalWebhookEvent>
     */
    public function events(): array
    {
        return array_map(
            static fn ( array $event ): PayPalWebhookEvent => PayPalWebhookEvent::fromArray( $event ),
            array_values( array_filter( (array) ( $this->raw['events'] ?? [] ), 'is_array' ) ),
        );
    }

    /**
     * The URL of the next page, or null on the last one.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function nextUrl(): ?string
    {
        return $this->link( 'next' );
    }
}
