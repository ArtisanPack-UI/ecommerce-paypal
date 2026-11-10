<?php

/**
 * Vault payment token webhook handler.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks\Handlers;

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalPaymentToken as PayPalTokenData;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the local vault in step with PayPal's (spec §10.3):
 *
 * - `VAULT.PAYMENT-TOKEN.DELETED` soft-deletes the local token (promoting
 *   another default), once PayPal confirms the token is gone (`404`), so a
 *   forged body can't remove a shopper's card;
 * - `VAULT.PAYMENT-TOKEN.CREATED` stores a token saved elsewhere (PayPal's
 *   dashboard, a save that finished after checkout) when its PayPal
 *   customer id belongs to one of the store's customers. The token is
 *   re-fetched, and only its display data is kept (D8).
 *
 * Tokens of the other mode, or of PayPal customers the store doesn't know,
 * are ignored.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class VaultEventHandler extends WebhookEventHandler
{
    /**
     * Handles a vault event.
     *
     * @since 1.0.0
     *
     * @param  string                $type      Event type.
     * @param  array<string, mixed>  $resource  Event resource (untrusted).
     * @param  string                $eventId   Event id, for logs.
     *
     * @throws PayPalApiException When PayPal can't be reached (the job retries).
     *
     * @return void
     */
    public function handle( string $type, array $resource, string $eventId ): void
    {
        $tokenId = isset( $resource['id'] ) && is_scalar( $resource['id'] ) ? trim( (string) $resource['id'] ) : '';

        if ( '' === $tokenId ) {
            return;
        }

        if ( 'VAULT.PAYMENT-TOKEN.DELETED' === $type ) {
            $this->deleted( $tokenId );

            return;
        }

        $this->created( $tokenId, $eventId );
    }

    /**
     * Soft-deletes the local copy of a token PayPal confirms is gone.
     *
     * @since 1.0.0
     *
     * @param  string  $tokenId  PayPal payment token id.
     *
     * @throws PayPalApiException When PayPal can't be reached.
     *
     * @return void
     */
    protected function deleted( string $tokenId ): void
    {
        $vault = app( PayPalVault::class );
        $token = PayPalPaymentToken::query()->forMode( $vault->mode() )->where( 'paypal_token_id', $tokenId )->first();

        if ( null === $token ) {
            return;
        }

        try {
            $this->client->vault()->getPaymentToken( $tokenId );

            // PayPal still has it: not a real deletion.
            Log::channel( self::logChannel() )->warning( 'Ignored a PayPal vault deletion event for a token PayPal still has.', [ 'token_id' => $tokenId ] );

            return;
        } catch ( PayPalApiException $exception ) {
            if ( 404 !== $exception->status ) {
                if ( $exception->isRetryable() ) {
                    throw $exception;
                }

                return;
            }
        }

        $vault->forget( $token );
    }

    /**
     * Stores a token saved outside this store's checkout.
     *
     * @since 1.0.0
     *
     * @param  string  $tokenId  PayPal payment token id.
     * @param  string  $eventId  Event id, for logs.
     *
     * @throws PayPalApiException When PayPal can't be reached.
     *
     * @return void
     */
    protected function created( string $tokenId, string $eventId ): void
    {
        $vault = app( PayPalVault::class );

        if ( ! $vault->enabled() ) {
            return;
        }

        /** @var PayPalTokenData|null $data */
        $data = $this->fetch( fn (): PayPalTokenData => $this->client->vault()->getPaymentToken( $tokenId ), $eventId );
        $type = $data?->sourceType();

        if ( null === $data || null === $type || ! in_array( $type, PayPalVault::VAULTABLE_SOURCES, true ) || '' === $data->id() ) {
            return;
        }

        $paypalCustomerId = $data->customerId();
        $customer         = null === $paypalCustomerId ? null : PayPalCustomer::query()
            ->forMode( $vault->mode() )
            ->where( 'paypal_customer_id', $paypalCustomerId )
            ->first();

        if ( null === $customer ) {
            return;
        }

        $existing = PayPalPaymentToken::withTrashed()->forMode( $vault->mode() )->where( 'paypal_token_id', $data->id() )->first();

        // A token the shopper removed here stays removed, and one already
        // stored keeps its last use.
        if ( null !== $existing ) {
            return;
        }

        $vault->save(
            customerId: $customer->customer_id,
            tokenId: $data->id(),
            gatewayKey: 'card' === $type ? PayPalCardGateway::KEY : PayPalWalletGateway::KEY,
            type: $type,
            display: PayPalVault::display( $type, $data->source() ),
            used: false,
        );
    }
}
