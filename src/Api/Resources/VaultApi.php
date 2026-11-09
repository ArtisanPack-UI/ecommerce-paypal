<?php

/**
 * PayPal Vault v3 endpoints.
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

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalPaymentTokenList;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalSetupToken;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

/**
 * `/v3/vault`: saved payment methods.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class VaultApi
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
     * Lists a customer's payment tokens.
     *
     * @since 1.0.0
     *
     * @param  string  $customerId  PayPal customer id.
     * @param  int     $page        Page, from 1.
     * @param  int     $pageSize    Tokens per page (PayPal allows 1–5).
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalPaymentTokenList
     */
    public function listPaymentTokens( string $customerId, int $page = 1, int $pageSize = 5 ): PayPalPaymentTokenList
    {
        return PayPalPaymentTokenList::fromArray( $this->client->get( '/v3/vault/payment-tokens', [
            'customer_id'    => $customerId,
            'page'           => max( 1, $page ),
            'page_size'      => min( 5, max( 1, $pageSize ) ),
            'total_required' => 'true',
        ] ) );
    }

    /**
     * Fetches a payment token.
     *
     * @since 1.0.0
     *
     * @param  string  $tokenId  Payment token id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalPaymentToken
     */
    public function getPaymentToken( string $tokenId ): PayPalPaymentToken
    {
        return PayPalPaymentToken::fromArray( $this->client->get( '/v3/vault/payment-tokens/' . rawurlencode( $tokenId ) ) );
    }

    /**
     * Deletes a payment token at PayPal.
     *
     * @since 1.0.0
     *
     * @param  string  $tokenId    Payment token id.
     * @param  string  $requestId  `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return void
     */
    public function deletePaymentToken( string $tokenId, string $requestId ): void
    {
        $this->client->delete( '/v3/vault/payment-tokens/' . rawurlencode( $tokenId ), $requestId );
    }

    /**
     * Creates a setup token, to save a payment method without a purchase.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $paymentSource  Payment source (`[ 'card' => [ … ] ]` or `[ 'paypal' => [ … ] ]`).
     * @param  string                $requestId      `PayPal-Request-Id`.
     * @param  string|null           $customerId     Existing PayPal customer id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalSetupToken
     */
    public function createSetupToken( array $paymentSource, string $requestId, ?string $customerId = null ): PayPalSetupToken
    {
        $body = [ 'payment_source' => $paymentSource ];

        if ( null !== $customerId && '' !== $customerId ) {
            $body['customer'] = [ 'id' => $customerId ];
        }

        return PayPalSetupToken::fromArray( $this->client->post( '/v3/vault/setup-tokens', $body, $requestId ) );
    }

    /**
     * Fetches a setup token.
     *
     * @since 1.0.0
     *
     * @param  string  $setupTokenId  Setup token id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalSetupToken
     */
    public function getSetupToken( string $setupTokenId ): PayPalSetupToken
    {
        return PayPalSetupToken::fromArray( $this->client->get( '/v3/vault/setup-tokens/' . rawurlencode( $setupTokenId ) ) );
    }
}
