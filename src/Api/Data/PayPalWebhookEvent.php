<?php

/**
 * PayPal Webhooks v1 event.
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
 * A webhook event, as delivered or as listed by
 * `/v1/notifications/webhooks-events`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWebhookEvent extends PayPalData
{
    /**
     * The event name (`PAYMENT.CAPTURE.COMPLETED`, …).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function eventType(): string
    {
        return (string) ( $this->raw['event_type'] ?? '' );
    }

    /**
     * The resource type (`capture`, `refund`, `checkout-order`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function resourceType(): ?string
    {
        return $this->string( 'resource_type' );
    }

    /**
     * The resource the event is about.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function resource(): array
    {
        return (array) ( $this->raw['resource'] ?? [] );
    }

    /**
     * When PayPal created the event, or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function createTime(): ?string
    {
        return $this->string( 'create_time' );
    }
}
