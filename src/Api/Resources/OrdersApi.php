<?php

/**
 * PayPal Orders v2 endpoints.
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

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

/**
 * `/v2/checkout/orders`.
 *
 * Mutating calls ask for the full representation back
 * (`Prefer: return=representation`), so the result carries captures,
 * authorizations, and the payment source.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class OrdersApi
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
     * Creates an order.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $payload    Order body (see {@see \ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder}).
     * @param  string                $requestId  `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalOrder
     */
    public function create( array $payload, string $requestId ): PayPalOrder
    {
        return PayPalOrder::fromArray( $this->client->post( '/v2/checkout/orders', $payload, $requestId, self::representation() ) );
    }

    /**
     * Fetches an order.
     *
     * @since 1.0.0
     *
     * @param  string  $orderId  PayPal Order id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalOrder
     */
    public function get( string $orderId ): PayPalOrder
    {
        return PayPalOrder::fromArray( $this->client->get( '/v2/checkout/orders/' . rawurlencode( $orderId ) ) );
    }

    /**
     * Updates an order before it is approved (amount, items, shipping).
     *
     * @since 1.0.0
     *
     * @param  string                            $orderId     PayPal Order id.
     * @param  array<int, array<string, mixed>>  $operations  JSON Patch operations (`op`, `path`, `value`).
     * @param  string                            $requestId   `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return void
     */
    public function patch( string $orderId, array $operations, string $requestId ): void
    {
        $this->client->patch( '/v2/checkout/orders/' . rawurlencode( $orderId ), array_values( $operations ), $requestId );
    }

    /**
     * Authorizes an approved order (intent `AUTHORIZE`).
     *
     * @since 1.0.0
     *
     * @param  string  $orderId    PayPal Order id.
     * @param  string  $requestId  `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalOrder
     */
    public function authorize( string $orderId, string $requestId ): PayPalOrder
    {
        return PayPalOrder::fromArray( $this->client->post(
            '/v2/checkout/orders/' . rawurlencode( $orderId ) . '/authorize',
            [],
            $requestId,
            self::representation(),
        ) );
    }

    /**
     * Captures an approved order (intent `CAPTURE`).
     *
     * @since 1.0.0
     *
     * @param  string                     $orderId        PayPal Order id.
     * @param  string                     $requestId      `PayPal-Request-Id`.
     * @param  array<string, mixed>|null  $paymentSource  Payment source, for vaulted payments.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalOrder
     */
    public function capture( string $orderId, string $requestId, ?array $paymentSource = null ): PayPalOrder
    {
        return PayPalOrder::fromArray( $this->client->post(
            '/v2/checkout/orders/' . rawurlencode( $orderId ) . '/capture',
            null === $paymentSource ? [] : [ 'payment_source' => $paymentSource ],
            $requestId,
            self::representation(),
        ) );
    }

    /**
     * The `Prefer` header asking for the full resource.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    private static function representation(): array
    {
        return [ 'Prefer' => 'return=representation' ];
    }
}
