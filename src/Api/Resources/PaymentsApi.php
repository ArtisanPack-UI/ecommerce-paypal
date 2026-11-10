<?php

/**
 * PayPal Payments v2 endpoints.
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

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalAuthorization;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalRefund;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

/**
 * `/v2/payments`: authorizations, captures, and refunds.
 *
 * Money arguments are PayPal money objects
 * (`[ 'currency_code' => 'USD', 'value' => '10.00' ]`); build them with
 * {@see \ArtisanPackUI\EcommercePayPal\Support\PayPalMoney::toPayPal()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PaymentsApi
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
     * Fetches an authorization.
     *
     * @since 1.0.0
     *
     * @param  string  $authorizationId  Authorization id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalAuthorization
     */
    public function getAuthorization( string $authorizationId ): PayPalAuthorization
    {
        return PayPalAuthorization::fromArray( $this->client->get( '/v2/payments/authorizations/' . rawurlencode( $authorizationId ) ) );
    }

    /**
     * Captures an authorization.
     *
     * @since 1.0.0
     *
     * @param  string                                           $authorizationId  Authorization id.
     * @param  string                                           $requestId        `PayPal-Request-Id`.
     * @param  array{currency_code: string, value: string}|null  $amount           Amount, or null for the full authorization.
     * @param  bool                                             $finalCapture     Whether no more captures follow.
     * @param  string|null                                      $invoiceId        Invoice id.
     * @param  string|null                                      $noteToPayer      Note shown to the payer.
     * @param  string|null                                      $softDescriptor   Statement descriptor.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalCapture
     */
    public function captureAuthorization(
        string $authorizationId,
        string $requestId,
        ?array $amount = null,
        bool $finalCapture = true,
        ?string $invoiceId = null,
        ?string $noteToPayer = null,
        ?string $softDescriptor = null,
    ): PayPalCapture {
        $body = array_filter( [
            'amount'          => $amount,
            'final_capture'   => $finalCapture,
            'invoice_id'      => $invoiceId,
            'note_to_payer'   => $noteToPayer,
            'soft_descriptor' => $softDescriptor,
        ], static fn ( mixed $value ): bool => null !== $value );

        return PayPalCapture::fromArray( $this->client->post(
            '/v2/payments/authorizations/' . rawurlencode( $authorizationId ) . '/capture',
            $body,
            $requestId,
            self::representation(),
        ) );
    }

    /**
     * Voids an authorization.
     *
     * @since 1.0.0
     *
     * @param  string  $authorizationId  Authorization id.
     * @param  string  $requestId        `PayPal-Request-Id`.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalAuthorization|null The voided authorization, or null when PayPal returned no body.
     */
    public function voidAuthorization( string $authorizationId, string $requestId ): ?PayPalAuthorization
    {
        $body = $this->client->post(
            '/v2/payments/authorizations/' . rawurlencode( $authorizationId ) . '/void',
            [],
            $requestId,
            self::representation(),
        );

        return [] === $body ? null : PayPalAuthorization::fromArray( $body );
    }

    /**
     * Reauthorizes an authorization after its honor period.
     *
     * @since 1.0.0
     *
     * @param  string                                           $authorizationId  Authorization id.
     * @param  string                                           $requestId        `PayPal-Request-Id`.
     * @param  array{currency_code: string, value: string}|null  $amount           Amount, or null for the original amount.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalAuthorization The new authorization.
     */
    public function reauthorize( string $authorizationId, string $requestId, ?array $amount = null ): PayPalAuthorization
    {
        return PayPalAuthorization::fromArray( $this->client->post(
            '/v2/payments/authorizations/' . rawurlencode( $authorizationId ) . '/reauthorize',
            null === $amount ? [] : [ 'amount' => $amount ],
            $requestId,
            self::representation(),
        ) );
    }

    /**
     * Fetches a capture.
     *
     * @since 1.0.0
     *
     * @param  string  $captureId  Capture id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalCapture
     */
    public function getCapture( string $captureId ): PayPalCapture
    {
        return PayPalCapture::fromArray( $this->client->get( '/v2/payments/captures/' . rawurlencode( $captureId ) ) );
    }

    /**
     * Refunds a capture, fully or partly.
     *
     * @since 1.0.0
     *
     * @param  string                                           $captureId    Capture id.
     * @param  string                                           $requestId    `PayPal-Request-Id`.
     * @param  array{currency_code: string, value: string}|null  $amount       Amount, or null for the rest of the capture.
     * @param  string|null                                      $invoiceId    Invoice id.
     * @param  string|null                                      $noteToPayer  Reason shown to the payer (255 characters at most).
     * @param  string|null                                      $customId     Custom id (the engine refund id).
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalRefund
     */
    public function refundCapture(
        string $captureId,
        string $requestId,
        ?array $amount = null,
        ?string $invoiceId = null,
        ?string $noteToPayer = null,
        ?string $customId = null,
    ): PayPalRefund {
        $body = array_filter( [
            'amount'        => $amount,
            'invoice_id'    => $invoiceId,
            'note_to_payer' => null === $noteToPayer ? null : mb_substr( $noteToPayer, 0, 255 ),
            'custom_id'     => $customId,
        ], static fn ( mixed $value ): bool => null !== $value );

        return PayPalRefund::fromArray( $this->client->post(
            '/v2/payments/captures/' . rawurlencode( $captureId ) . '/refund',
            $body,
            $requestId,
            self::representation(),
        ) );
    }

    /**
     * Fetches a refund.
     *
     * @since 1.0.0
     *
     * @param  string  $refundId  Refund id.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalRefund
     */
    public function getRefund( string $refundId ): PayPalRefund
    {
        return PayPalRefund::fromArray( $this->client->get( '/v2/payments/refunds/' . rawurlencode( $refundId ) ) );
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
