<?php

/**
 * PayPal Customer Disputes v1 resource.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api\Resources;

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalDispute;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

/**
 * `/v1/customer/disputes`: read-only. Dispute webhooks are re-fetched from
 * here so a note is never written from an unconfirmed payload (spec §8.3).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class DisputesApi
{
    /**
     * Creates the resource.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  Client.
     */
    public function __construct( private readonly PayPalClient $client )
    {
    }

    /**
     * Fetches a dispute.
     *
     * @since 1.0.0
     *
     * @param  string  $disputeId  Dispute id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalDispute
     */
    public function get( string $disputeId ): PayPalDispute
    {
        return PayPalDispute::fromArray( $this->client->get( '/v1/customer/disputes/' . rawurlencode( $disputeId ) ) );
    }
}
