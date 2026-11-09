<?php

/**
 * PayPal Webhooks v1 webhook.
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
 * A webhook subscription (`/v1/notifications/webhooks/{id}`).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWebhook extends PayPalData
{
    /**
     * The URL PayPal posts events to.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function url(): string
    {
        return (string) ( $this->raw['url'] ?? '' );
    }

    /**
     * The subscribed event names.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function eventTypes(): array
    {
        $names = [];

        foreach ( (array) ( $this->raw['event_types'] ?? [] ) as $type ) {
            if ( is_array( $type ) && isset( $type['name'] ) ) {
                $names[] = (string) $type['name'];
            }
        }

        return $names;
    }
}
