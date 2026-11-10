<?php

/**
 * PayPal vault service.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Vault;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Api\PayPalCredentials;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalSavedMethodException;
use ArtisanPackUI\EcommercePayPal\Jobs\DeletePayPalVaultTokens;
use ArtisanPackUI\EcommercePayPal\Models\PayPalCustomer;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Saved payment methods (spec §10): who may save one, what is stored when
 * PayPal vaults it, the one-default rule, and erasure.
 *
 * Only references and display data are kept (D8). Every write goes through
 * here so the one-default-per-customer-per-mode rule holds whatever UI or
 * API made the change.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalVault
{
    /**
     * The cart meta flag the shopper's "save" choice is kept in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SAVE_FLAG = 'save_payment_method';

    /**
     * Payment sources PayPal can vault during a purchase.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const VAULTABLE_SOURCES = [ 'paypal', 'card', 'venmo' ];

    /**
     * The payment-session context key naming the saved card (a local
     * {@see PayPalPaymentToken} id) the shopper chose to pay with
     * (spec §10.2).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SAVED_TOKEN_CONTEXT = 'paypal_saved_token';

    /**
     * The `cart.meta.paypal` key recording which saved card paid for which
     * PayPal Order, so the card's `last_used_at` can be updated once the
     * payment succeeds.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SAVED_TOKEN_META = 'saved_token';

    /**
     * PayPal error issues that always mean a vaulted token can no longer be
     * used.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const INVALID_TOKEN_ISSUES = [
        'TOKEN_ID_NOT_FOUND',
        'PAYMENT_SOURCE_CANNOT_BE_USED',
    ];

    /**
     * Generic "not found" issues, which only mean the token is invalid when
     * PayPal points them at `vault_id`.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const MISSING_RESOURCE_ISSUES = [
        'INVALID_RESOURCE_ID',
        'RESOURCE_NOT_FOUND',
    ];

    /**
     * Creates the service.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     */
    public function __construct( protected PayPalClient $client )
    {
    }

    /**
     * Whether vaulting is switched on.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function enabled(): bool
    {
        return (bool) config( 'artisanpack.ecommerce-paypal.vault.enabled', false );
    }

    /**
     * The current PayPal mode (`sandbox` or `live`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function mode(): string
    {
        return $this->client->environment()->mode;
    }

    /**
     * The signed-in customer who owns `$cart`, when vaulting is on, or null.
     *
     * Guests never get one: the cart must belong to an engine customer and
     * that customer must be the signed-in user's.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Customer|null
     */
    public function shopperFor( Cart $cart ): ?Customer
    {
        if ( ! $this->enabled() || null === $cart->customer_id || ! Auth::check() ) {
            return null;
        }

        $customer = Customer::forUser( Auth::user() );

        return null !== $customer && (int) $customer->getKey() === (int) $cart->customer_id ? $customer : null;
    }

    /**
     * Whether the shopper asked to save the payment method used for `$cart`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return bool
     */
    public function wantsToSave( Cart $cart ): bool
    {
        $meta = (array) ( $cart->getAttribute( 'meta' ) ?? [] );

        return true === ( $meta['paypal'][ self::SAVE_FLAG ] ?? false );
    }

    /**
     * Records the shopper's "save" choice on `cart.meta.paypal`.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     * @param  bool  $save  Whether to save.
     *
     * @return void
     */
    public function rememberChoice( Cart $cart, bool $save ): void
    {
        $meta   = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
        $paypal = is_array( $meta['paypal'] ?? null ) ? $meta['paypal'] : [];

        if ( $save ) {
            $paypal[ self::SAVE_FLAG ] = true;
        } else {
            unset( $paypal[ self::SAVE_FLAG ] );
        }

        $meta['paypal'] = $paypal;

        $cart->setAttribute( 'meta', $meta );

        if ( $cart->exists ) {
            $cart->save();
        }
    }

    /**
     * Whether the Order for `$cart` should ask PayPal to vault `$source`:
     * vaulting is on, the source can be vaulted, the shopper is signed in
     * and owns the cart, and they ticked "save".
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart    Cart.
     * @param  string  $source  PayPal payment source.
     *
     * @return bool
     */
    public function savesFor( Cart $cart, string $source ): bool
    {
        return in_array( $source, [ 'paypal', 'card' ], true )
            && $this->wantsToSave( $cart )
            && null !== $this->shopperFor( $cart );
    }

    /**
     * The PayPal vault customer id of `$customerId` in the current mode, or
     * null.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Engine customer id.
     *
     * @return string|null
     */
    public function paypalCustomerId( int $customerId ): ?string
    {
        $id = PayPalCustomer::query()->forMode( $this->mode() )->where( 'customer_id', $customerId )->value( 'paypal_customer_id' );

        return is_string( $id ) && '' !== $id ? $id : null;
    }

    /**
     * Mints an `id_token` for a returning buyer, so PayPal shows their saved
     * wallet (spec §10.2), or null when they have no PayPal customer id or
     * PayPal can't mint one (the checkout goes on without it).
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Engine customer.
     *
     * @return string|null
     */
    public function idTokenFor( Customer $customer ): ?string
    {
        $paypalCustomerId = $this->paypalCustomerId( (int) $customer->getKey() );

        if ( null === $paypalCustomerId ) {
            return null;
        }

        try {
            return $this->client->idToken( $paypalCustomerId );
        } catch ( PayPalApiException $exception ) {
            report( $exception );

            return null;
        }
    }

    /**
     * Stores the payment method PayPal vaulted while paying for `$order`
     * (`payment_source.*.attributes.vault`), with the PayPal customer id.
     *
     * Nothing is stored for guest orders, for sources PayPal doesn't vault,
     * or when PayPal returned no token id (it may still be vaulting; the
     * `VAULT.PAYMENT-TOKEN.CREATED` webhook reports it then).
     *
     * @since 1.0.0
     *
     * @param  Order        $order        Engine order.
     * @param  PayPalOrder  $paypalOrder  PayPal Order, as captured or authorized.
     * @param  string       $gatewayKey   Gateway that took the payment.
     *
     * @return PayPalPaymentToken|null
     */
    public function recordFromOrder( Order $order, PayPalOrder $paypalOrder, string $gatewayKey ): ?PayPalPaymentToken
    {
        $vault  = $paypalOrder->vault();
        $source = $paypalOrder->paymentSourceType();

        if ( null === $vault || null === $order->customer_id || ! in_array( $source, self::VAULTABLE_SOURCES, true ) ) {
            return null;
        }

        $tokenId = isset( $vault['id'] ) && is_string( $vault['id'] ) ? trim( $vault['id'] ) : '';

        if ( '' === $tokenId ) {
            return null;
        }

        $paypalCustomerId = isset( $vault['customer']['id'] ) && is_string( $vault['customer']['id'] ) ? trim( $vault['customer']['id'] ) : null;

        return $this->save(
            customerId: (int) $order->customer_id,
            tokenId: $tokenId,
            gatewayKey: $gatewayKey,
            type: $source,
            display: self::display( $source, (array) $paypalOrder->get( 'payment_source.' . $source, [] ) ),
            paypalCustomerId: '' === $paypalCustomerId ? null : $paypalCustomerId,
        );
    }

    /**
     * Stores (or restores) a vaulted payment method and the customer's
     * PayPal customer id. The first method a customer saves in a mode
     * becomes their default.
     *
     * @since 1.0.0
     *
     * @param  int                    $customerId        Engine customer id.
     * @param  string                 $tokenId           PayPal payment token id.
     * @param  string                 $gatewayKey        Gateway the method belongs to.
     * @param  string                 $type              `paypal`, `card`, or `venmo`.
     * @param  array<string, string>  $display           Display data ({@see self::display()}).
     * @param  string|null            $paypalCustomerId  PayPal customer id.
     * @param  bool                   $used              Whether it was just used to pay (sets `last_used_at`).
     *
     * @return PayPalPaymentToken
     */
    public function save( int $customerId, string $tokenId, string $gatewayKey, string $type, array $display, ?string $paypalCustomerId = null, bool $used = true ): PayPalPaymentToken
    {
        $mode = $this->mode();

        return DB::transaction( function () use ( $customerId, $tokenId, $gatewayKey, $type, $display, $paypalCustomerId, $mode, $used ): PayPalPaymentToken {
            if ( null !== $paypalCustomerId ) {
                PayPalCustomer::query()->updateOrCreate(
                    [ 'customer_id' => $customerId, 'mode' => $mode ],
                    [ 'paypal_customer_id' => $paypalCustomerId ],
                );
            }

            $token = PayPalPaymentToken::withTrashed()
                ->forMode( $mode )
                ->where( 'paypal_token_id', $tokenId )
                ->lockForUpdate()
                ->first() ?? new PayPalPaymentToken( [ 'paypal_token_id' => $tokenId, 'mode' => $mode ] );

            $token->fill( [
                'customer_id' => $customerId,
                'gateway_key' => $gatewayKey,
                'type'        => $type,
                'display'     => [] === $display ? null : $display,
            ] );

            if ( $used ) {
                $token->last_used_at = Carbon::now();
            }

            $token->deleted_at = null;
            $token->is_default = $token->is_default || ! $this->hasDefault( $customerId, $mode, $token->getKey() );
            $token->save();

            return $token;
        } );
    }

    /**
     * Makes `$token` its customer's default in its mode, clearing the
     * others, so there is never more than one.
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken  $token  Token.
     *
     * @return PayPalPaymentToken
     */
    public function makeDefault( PayPalPaymentToken $token ): PayPalPaymentToken
    {
        return DB::transaction( static function () use ( $token ): PayPalPaymentToken {
            $siblings = PayPalPaymentToken::query()
                ->forCustomer( $token->customer_id )
                ->forMode( $token->mode );

            // Lock the customer's tokens first, so two requests can't each
            // leave a different default.
            ( clone $siblings )->lockForUpdate()->pluck( 'id' );

            ( clone $siblings )->whereKeyNot( $token->getKey() )->where( 'is_default', true )->update( [ 'is_default' => false ] );
            ( clone $siblings )->whereKey( $token->getKey() )->update( [ 'is_default' => true ] );

            return $token->refresh();
        } );
    }

    /**
     * The customer's default token in the current mode, or null.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Engine customer id.
     *
     * @return PayPalPaymentToken|null
     */
    public function defaultFor( int $customerId ): ?PayPalPaymentToken
    {
        return PayPalPaymentToken::query()->forCustomer( $customerId )->forMode( $this->mode() )->where( 'is_default', true )->first();
    }

    /**
     * The customer's saved cards in the current mode: the default first,
     * then the most recently used.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Engine customer id.
     *
     * @return Collection<int, PayPalPaymentToken>
     */
    public function savedCardsFor( int $customerId ): Collection
    {
        return $this->tokensFor( $customerId )->filter( static fn ( PayPalPaymentToken $token ): bool => 'card' === $token->type )->values();
    }

    /**
     * Every saved payment method of the customer in the current mode: the
     * default first, then the most recently used.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Engine customer id.
     *
     * @return Collection<int, PayPalPaymentToken>
     */
    public function tokensFor( int $customerId ): Collection
    {
        return PayPalPaymentToken::query()
            ->forCustomer( $customerId )
            ->forMode( $this->mode() )
            ->orderByDesc( 'is_default' )
            ->orderByRaw( 'last_used_at IS NULL' )
            ->orderByDesc( 'last_used_at' )
            ->orderByDesc( 'id' )
            ->get();
    }

    /**
     * One of the customer's own tokens in the current mode, or null. Every
     * lookup by id goes through here (or {@see self::savedCardFor()}), so a
     * customer can never reach another customer's token.
     *
     * @since 1.0.0
     *
     * @param  int         $customerId  Engine customer id.
     * @param  int|string  $tokenId     Local token id.
     *
     * @return PayPalPaymentToken|null
     */
    public function tokenFor( int $customerId, int|string $tokenId ): ?PayPalPaymentToken
    {
        if ( ! is_numeric( $tokenId ) || (int) $tokenId < 1 ) {
            return null;
        }

        return PayPalPaymentToken::query()
            ->forCustomer( $customerId )
            ->forMode( $this->mode() )
            ->whereKey( (int) $tokenId )
            ->first();
    }

    /**
     * The saved card `$tokenId` when the signed-in shopper who owns `$cart`
     * may pay for it with that card (vaulting on, their own card, this
     * mode, not deleted), or null.
     *
     * @since 1.0.0
     *
     * @param  Cart        $cart     Cart.
     * @param  int|string  $tokenId  Local token id.
     *
     * @return PayPalPaymentToken|null
     */
    public function savedCardFor( Cart $cart, int|string $tokenId ): ?PayPalPaymentToken
    {
        $shopper = $this->shopperFor( $cart );
        $token   = null === $shopper ? null : $this->tokenFor( (int) $shopper->getKey(), $tokenId );

        return null !== $token && 'card' === $token->type ? $token : null;
    }

    /**
     * Records on `cart.meta.paypal` that `$token` pays for PayPal Order
     * `$paypalOrderId`, or clears the record when `$token` is null.
     *
     * @since 1.0.0
     *
     * @param  Cart                     $cart           Cart.
     * @param  PayPalPaymentToken|null  $token          Saved card, or null.
     * @param  string                   $paypalOrderId  PayPal Order id.
     *
     * @return void
     */
    public function rememberSavedCard( Cart $cart, ?PayPalPaymentToken $token, string $paypalOrderId = '' ): void
    {
        $meta   = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
        $paypal = is_array( $meta['paypal'] ?? null ) ? $meta['paypal'] : [];

        if ( null === $token ) {
            if ( ! array_key_exists( self::SAVED_TOKEN_META, $paypal ) ) {
                return;
            }

            unset( $paypal[ self::SAVED_TOKEN_META ] );
        } else {
            $paypal[ self::SAVED_TOKEN_META ] = [ 'id' => (int) $token->getKey(), 'order_id' => $paypalOrderId ];
        }

        $meta['paypal'] = $paypal;

        $cart->setAttribute( 'meta', $meta );

        if ( $cart->exists ) {
            $cart->save();
        }
    }

    /**
     * Updates `last_used_at` of the saved card that paid for
     * `$paypalOrder`, when one did (spec §10.2).
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $paypalOrder  The PayPal Order that was paid.
     *
     * @return PayPalPaymentToken|null The card, when one was used.
     */
    public function recordSavedCardUse( PayPalOrder $paypalOrder ): ?PayPalPaymentToken
    {
        if ( '' === $paypalOrder->id() ) {
            return null;
        }

        $cart  = Cart::query()->where( 'payment_reference', $paypalOrder->id() )->first();
        $meta  = (array) ( $cart?->getAttribute( 'meta' ) ?? [] );
        $saved = $meta['paypal'][ self::SAVED_TOKEN_META ] ?? null;

        if ( ! is_array( $saved ) || ( $saved['order_id'] ?? null ) !== $paypalOrder->id() || ! is_numeric( $saved['id'] ?? null ) ) {
            return null;
        }

        $token = PayPalPaymentToken::query()->whereKey( (int) $saved['id'] )->where( 'customer_id', (int) $cart->customer_id )->first();

        $token?->forceFill( [ 'last_used_at' => Carbon::now() ] )->save();

        return $token;
    }

    /**
     * Whether `$exception` means PayPal no longer accepts the vaulted token
     * the request used.
     *
     * @since 1.0.0
     *
     * @param  PayPalApiException  $exception  PayPal error.
     *
     * @return bool
     */
    public static function rejectsToken( PayPalApiException $exception ): bool
    {
        foreach ( $exception->details as $detail ) {
            $issue = is_array( $detail ) ? ( $detail['issue'] ?? null ) : null;

            if ( in_array( $issue, self::INVALID_TOKEN_ISSUES, true ) ) {
                return true;
            }

            // A missing resource is only the token when PayPal says so: any
            // other 404 or invalid id must never delete a shopper's card.
            if ( in_array( $issue, self::MISSING_RESOURCE_ISSUES, true ) && str_contains( (string) ( $detail['field'] ?? '' ), 'vault_id' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Soft-deletes a token locally (PayPal reported it invalid or deleted
     * it, or the shopper removed it). When it was the default, the
     * customer's most recently used remaining method becomes the default.
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken  $token  Token.
     *
     * @return void
     */
    public function forget( PayPalPaymentToken $token ): void
    {
        DB::transaction( function () use ( $token ): void {
            $wasDefault = (bool) $token->is_default;

            $token->forceFill( [ 'is_default' => false ] )->save();
            $token->delete();

            if ( ! $wasDefault ) {
                return;
            }

            $next = PayPalPaymentToken::query()
                ->forCustomer( $token->customer_id )
                ->forMode( $token->mode )
                ->orderByRaw( 'last_used_at IS NULL' )
                ->orderByDesc( 'last_used_at' )
                ->orderByDesc( 'id' )
                ->first();

            if ( null !== $next ) {
                $this->makeDefault( $next );
            }
        } );
    }

    /**
     * Forgets the token a {@see PayPalSavedMethodException} names, if it is
     * still stored: the removal the gateway made is lost when the payment
     * session ran inside a transaction that rolled back with the exception.
     *
     * @since 1.0.0
     *
     * @param  PayPalSavedMethodException  $exception  Exception.
     *
     * @return void
     */
    public function forgetRejected( PayPalSavedMethodException $exception ): void
    {
        $token = null === $exception->token ? null : PayPalPaymentToken::query()->whereKey( $exception->token->getKey() )->first();

        if ( null !== $token ) {
            $this->forget( $token );
        }
    }

    /**
     * Deletes `$token` at PayPal, then locally (spec §10.3). A token PayPal
     * no longer has counts as deleted there.
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken  $token  Token.
     *
     * @throws PayPalApiException When PayPal refuses or can't be reached; the local row is kept.
     *
     * @return void
     */
    public function remove( PayPalPaymentToken $token ): void
    {
        if ( $token->mode === $this->mode() ) {
            $this->deleteAtPayPal( [ $token->paypal_token_id ] );
        }

        $this->forget( $token );
    }

    /**
     * Erases a deleted customer's vault data (spec §10.1).
     *
     * The local rows, trashed ones included, are removed right away. The
     * current mode's tokens are then deleted at PayPal by a queued job that
     * retries while PayPal is unreachable; the other mode's credentials
     * aren't available, so its tokens can only be removed locally.
     *
     * @since 1.0.0
     *
     * @param  int  $customerId  Engine customer id.
     *
     * @return void
     */
    public function forgetCustomer( int $customerId ): void
    {
        if ( ! Schema::hasTable( 'ecommerce_paypal_payment_tokens' ) ) {
            return;
        }

        $mode     = $this->mode();
        $tokenIds = PayPalPaymentToken::withTrashed()
            ->forCustomer( $customerId )
            ->forMode( $mode )
            ->pluck( 'paypal_token_id' )
            ->all();

        DB::transaction( static function () use ( $customerId ): void {
            PayPalPaymentToken::withTrashed()->forCustomer( $customerId )->forceDelete();
            PayPalCustomer::query()->where( 'customer_id', $customerId )->delete();
        } );

        if ( [] !== $tokenIds && PayPalCredentials::configured() ) {
            DeletePayPalVaultTokens::dispatch( array_values( $tokenIds ), $mode );
        }
    }

    /**
     * Deletes `$tokenIds` at PayPal. A token PayPal no longer has counts as
     * deleted.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $tokenIds  PayPal payment token ids.
     *
     * @throws PayPalApiException When PayPal refuses or can't be reached.
     *
     * @return void
     */
    public function deleteAtPayPal( array $tokenIds ): void
    {
        foreach ( $tokenIds as $tokenId ) {
            try {
                $this->client->vault()->deletePaymentToken( $tokenId, 'vault-delete-' . $tokenId );
            } catch ( PayPalApiException $exception ) {
                if ( 404 !== $exception->status ) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Deletes every stored token of the current mode at PayPal, for an
     * uninstall with `--purge` (the engine drops the tables afterwards).
     *
     * @since 1.0.0
     *
     * @return array{deleted: int, failed: int, skipped: int} `skipped` counts tokens of the other mode, which these credentials can't delete.
     */
    public function purge(): array
    {
        $result = [ 'deleted' => 0, 'failed' => 0, 'skipped' => 0 ];

        if ( ! Schema::hasTable( 'ecommerce_paypal_payment_tokens' ) ) {
            return $result;
        }

        $mode = $this->mode();

        PayPalPaymentToken::withTrashed()->orderBy( 'id' )->each( function ( PayPalPaymentToken $token ) use ( $mode, &$result ): void {
            if ( $token->mode !== $mode ) {
                $result['skipped']++;

                return;
            }

            try {
                $this->deleteAtPayPal( [ $token->paypal_token_id ] );
                $result['deleted']++;
            } catch ( Throwable $exception ) {
                report( $exception );
                $result['failed']++;
            }
        } );

        return $result;
    }

    /**
     * The display data kept for a vaulted `$source`: card brand, last
     * digits, and expiry, or the PayPal / Venmo email.
     *
     * @since 1.0.0
     *
     * @param  string                $source   Payment source type.
     * @param  array<string, mixed>  $details  `payment_source.{source}`.
     *
     * @return array<string, string>
     */
    public static function display( string $source, array $details ): array
    {
        $values = 'card' === $source
            ? [
                'brand'       => $details['brand'] ?? null,
                'last_digits' => $details['last_digits'] ?? null,
                'expiry'      => $details['expiry'] ?? null,
            ]
            : [ 'email' => $details['email_address'] ?? null ];

        $display = [];

        foreach ( $values as $key => $value ) {
            if ( is_string( $value ) && '' !== trim( $value ) ) {
                $display[ $key ] = mb_substr( trim( $value ), 0, 'last_digits' === $key ? 4 : 254 );
            }
        }

        return $display;
    }

    /**
     * Whether the customer has a default token in `$mode` other than
     * `$except`.
     *
     * @since 1.0.0
     *
     * @param  int       $customerId  Engine customer id.
     * @param  string    $mode        Mode.
     * @param  int|null  $except      Token id to leave out.
     *
     * @return bool
     */
    protected function hasDefault( int $customerId, string $mode, ?int $except ): bool
    {
        return PayPalPaymentToken::query()
            ->forCustomer( $customerId )
            ->forMode( $mode )
            ->where( 'is_default', true )
            ->when( null !== $except, static fn ( $query) => $query->whereKeyNot( $except ) )
            ->exists();
    }
}
