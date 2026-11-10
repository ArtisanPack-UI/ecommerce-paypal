<?php

/**
 * Base PayPal gateway.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Gateways;

use ArtisanPackUI\Ecommerce\Contracts\AcceptsPaymentOptions;
use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Exceptions\PaymentCurrencyMismatchException;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Models\OrderTimelineEntry;
use ArtisanPackUI\Ecommerce\Models\Refund;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalAuthorization;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalCapture;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalRefund;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalSavedMethodException;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCurrencies;
use ArtisanPackUI\EcommercePayPal\Support\PayPalDeclines;
use ArtisanPackUI\EcommercePayPal\Support\PayPalLocale;
use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalOrderLocator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Money\Currency;
use Money\Money;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * What the four PayPal gateways share (spec §6.1, D3): the API client, the
 * order payload builder, availability, and the mapping from PayPal Order
 * states to the engine's normalized session statuses.
 *
 * Each subclass supplies its key, label, PayPal payment source, the config
 * method it is switched on by, and its storefront driver. Only the `paypal`
 * gateway ({@see PayPalWalletGateway}) serves webhooks; a webhook posted to
 * a sibling's URL is refused with `use_paypal_endpoint`.
 *
 * Saving a payment method and paying with a saved card come in as payment
 * options ({@see AcceptsPaymentOptions}), from API storefronts and the
 * Livewire drivers alike.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalGateway implements AcceptsPaymentOptions, PaymentGateway, RendersClientPayment
{
    /**
     * Payment option: save the PayPal account or card for faster checkout
     * (spec §10.2). Boolean; `paypal` and `paypal-card` while vaulting is on
     * and the shopper is signed in.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SAVE_OPTION = 'save_payment_method';

    /**
     * Payment option: pay with this saved card, the local token id from
     * `GET me/paypal/payment-methods` (spec §10.2). `paypal-card` only.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SAVED_METHOD_OPTION = 'saved_payment_method';

    /**
     * Payment sources whose 3-D Secure result the liability-shift policy
     * checks (spec §9.4).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const CARD_SOURCES = [ 'card', 'apple_pay', 'google_pay' ];

    /**
     * Days a PayPal authorization stays valid, counted from the first
     * authorization (spec §7.4).
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const AUTHORIZATION_VALIDITY_DAYS = 29;

    /**
     * Fired once per order when a capture takes (or starts taking) money:
     * `(Order $order, string $captureId, string $status, PayPalOrder $paypalOrder, PayPalGateway $gateway)`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAPTURED_HOOK = 'ap.ecommerce.paypal.captured';

    /**
     * Filters the client config's `options`:
     * `(array $options, Cart $cart, PaymentSession $session, PayPalGateway $gateway)`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CLIENT_OPTIONS_HOOK = 'ap.ecommerce.paypal.clientOptions';

    /**
     * Creates the gateway.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient         $client    API client.
     * @param  OrderPayloadBuilder  $payloads  Order payload builder.
     */
    public function __construct(
        protected PayPalClient $client,
        protected OrderPayloadBuilder $payloads,
    ) {
    }

    /**
     * The PayPal `payment_source` key orders are created with (`paypal`,
     * `card`, `apple_pay`, `google_pay`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract public function paymentSource(): string;

    /**
     * The `methods.*` config key that switches this gateway on, and the
     * {@see PayPalCurrencies} method its currencies come from.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract public function method(): string;

    /**
     * The storefront driver that renders this gateway (`clientConfig.driver`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract public function driver(): string;

    /**
     * The API client.
     *
     * @since 1.0.0
     *
     * @return PayPalClient
     */
    public function client(): PayPalClient
    {
        return $this->client;
    }

    /**
     * The order payload builder.
     *
     * @since 1.0.0
     *
     * @return OrderPayloadBuilder
     */
    public function payloads(): OrderPayloadBuilder
    {
        return $this->payloads;
    }

    /**
     * Whether the store owner switched this method on.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) config( 'artisanpack.ecommerce-paypal.methods.' . $this->method() . '.enabled', false );
    }

    /**
     * Whether checkout should offer this gateway for `$cart` (spec §6.2):
     * the method is on, PayPal takes the cart's currency for it, and there
     * is something to pay once the total is in PayPal's units (a HUF total
     * under half a forint rounds to nothing).
     *
     * PayPal takes HUF and TWD in whole units only, so a total with a
     * fraction ({@see PayPalMoney::isExact()}) isn't offered: PayPal would
     * charge a rounded amount the engine could never finalize against.
     *
     * Device eligibility (Apple Pay, Google Pay) and buyer-country
     * eligibility (Venmo, Pay Later) are decided by the JS SDK.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart being checked out.
     *
     * @return bool
     */
    public function isAvailableFor( Cart $cart ): bool
    {
        $currency = strtoupper( (string) ( $cart->getAttribute( 'currency' ) ?: $cart->getAttribute( 'total_currency' ) ?: '' ) );

        if ( ! $this->isEnabled() || '' === $currency || ! PayPalCurrencies::supports( $this->method(), $currency ) ) {
            return false;
        }

        $total = new Money( (int) ( $cart->getAttribute( 'total_amount' ) ?? 0 ), new Currency( $currency ) );

        return PayPalMoney::toUnits( $total ) > 0 && PayPalMoney::isExact( $total );
    }

    /**
     * Refunds are supported for every PayPal method.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsRefunds(): bool
    {
        return true;
    }

    /**
     * Partial refunds are supported for every PayPal method.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsPartialRefunds(): bool
    {
        return true;
    }

    /**
     * Saved instruments are off unless a subclass vaults (spec §10.2).
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function supportsSavedInstruments(): bool
    {
        return false;
    }

    /**
     * The payment options this gateway accepts for `$cart` (#42):
     * {@see self::SAVE_OPTION} on gateways that vault, and
     * {@see self::SAVED_METHOD_OPTION} on the card gateway, both only while
     * vaulting is on and the cart belongs to the signed-in shopper. None
     * otherwise, so the engine refuses any option (guests included).
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return array<string, array<int, string>>
     */
    public function paymentOptionRules( Cart $cart ): array
    {
        if ( ! $this->supportsSavedInstruments() || null === app( PayPalVault::class )->shopperFor( $cart ) ) {
            return [];
        }

        $rules = [ self::SAVE_OPTION => [ 'sometimes', 'boolean' ] ];

        if ( 'card' === $this->paymentSource() ) {
            $rules[ self::SAVED_METHOD_OPTION ] = [ 'sometimes', 'integer', 'min:1' ];
        }

        return $rules;
    }

    /**
     * Whether `$session`'s Order was built for other options: a different
     * "save" choice than `cart.meta.paypal` records, or a different saved
     * card than the one recorded for that Order.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  PaymentSession        $session  The cart's current session.
     * @param  array<string, mixed>  $options  Validated options.
     *
     * @return bool
     */
    public function paymentOptionsChanged( Cart $cart, PaymentSession $session, array $options ): bool
    {
        [ $save, $savedCard ] = self::requestedOptions( $options );

        $meta    = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
        $saved   = $meta['paypal'][ PayPalVault::SAVED_TOKEN_META ] ?? null;
        $current = is_array( $saved ) && ( $saved['order_id'] ?? null ) === $session->reference && is_numeric( $saved['id'] ?? null )
            ? (int) $saved['id']
            : null;

        return $save !== app( PayPalVault::class )->wantsToSave( $cart ) || $savedCard !== $current;
    }

    /**
     * Normalizes a PayPal Order's state to a `PaymentSession::STATUS_*`
     * value (spec §7.2), or null for a state the engine shouldn't act on.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $order  PayPal Order.
     *
     * @return string|null
     */
    public function sessionStatus( PayPalOrder $order ): ?string
    {
        return match ( $order->status() ) {
            'CREATED', 'SAVED'      => PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD,
            'PAYER_ACTION_REQUIRED' => PaymentSession::STATUS_REQUIRES_ACTION,
            'APPROVED'              => PaymentSession::STATUS_AUTHORIZED,
            'VOIDED'                => PaymentSession::STATUS_CANCELED,
            'COMPLETED'             => $this->completedStatus( $order ),
            default                 => null,
        };
    }

    /**
     * Creates the PayPal Order for `$cart` (spec §7.1).
     *
     * The body comes from {@see OrderPayloadBuilder} (and the
     * `ap.ecommerce.paypal.orderPayload` filter). Its `PayPal-Request-Id`
     * is derived from `$context['idempotency_key']` together with the cart
     * and gateway (`session-{cart}-{hash}`, at most 108 characters), since
     * the engine scopes its keys per shopper while PayPal's request ids are
     * global to the merchant. Without a key, a UUID kept on
     * `cart.meta.paypal.request_id` is used until it produces an Order
     * ({@see self::cartRequestId()}). The `invoice_id` is derived from the
     * request id, so a retried request always carries the same body.
     *
     * An Order PayPal returns for another cart (its `custom_id`) is refused.
     *
     * Payment options (`$context['payment_options']`, validated by the
     * engine against {@see self::paymentOptionRules()}) are applied first:
     * the "save" choice is recorded on the cart, and a saved card becomes
     * {@see PayPalVault::SAVED_TOKEN_CONTEXT}.
     *
     * With a saved card in the context ({@see PayPalVault::SAVED_TOKEN_CONTEXT}),
     * the Order pays with its `vault_id` (spec §10.2). A token PayPal
     * reports invalid is soft-deleted and the shopper told to pick again.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  `idempotency_key`, plus the payload builder's context.
     *
     * @throws RuntimeException           When the total isn't a whole amount in a currency PayPal takes whole (HUF, TWD).
     * @throws PayPalApiException         When PayPal refuses or can't be reached.
     * @throws PayPalSavedMethodException When the saved card can't be used (or PayPal rejected it).
     * @throws UnexpectedValueException   When PayPal's answer has no id or amount, or belongs to another cart.
     *
     * @return PaymentSession
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        $total = $this->cartTotal( $cart );

        if ( null !== $total && ! PayPalMoney::isExact( $total ) ) {
            throw new RuntimeException( __( 'PayPal only accepts whole :currency amounts.', [ 'currency' => $total->getCurrency()->getCode() ] ) );
        }

        $vault     = app( PayPalVault::class );
        $context   = $this->withPaymentOptions( $cart, $vault, $context );
        $keyed     = isset( $context['idempotency_key'] ) && '' !== trim( (string) $context['idempotency_key'] );
        $requestId = $keyed
            ? 'session-' . $cart->getKey() . '-' . substr( hash( 'sha256', $this->key() . '|' . (string) $context['idempotency_key'] ), 0, 40 )
            : $this->cartRequestId( $cart, self::savedCardVariant( $context ) );

        $context['attempt'] ??= substr( hash( 'sha256', $requestId ), 0, 10 );

        $payload   = $this->payloads->build( $cart, $this, $context );
        $savedCard = $this->savedCardIn( $cart, $payload, $context );

        try {
            $order = $this->client->orders()->create( $payload, $requestId );
        } catch ( PayPalApiException $exception ) {
            if ( null !== $savedCard && PayPalVault::rejectsToken( $exception ) ) {
                $vault->forget( $savedCard );
                $this->forgetAfterRequest( $savedCard );

                throw PayPalSavedMethodException::invalid( $savedCard, $exception );
            }

            throw $exception;
        }

        if ( '' === $order->id() ) {
            throw new UnexpectedValueException( 'PayPal created an order without an id.' );
        }

        if ( null !== $order->customId() && $order->customId() !== (string) $cart->getKey() ) {
            throw new UnexpectedValueException( 'PayPal returned an Order for another cart.' );
        }

        if ( $vault->enabled() ) {
            $vault->rememberSavedCard( $cart, $savedCard, $order->id() );
        }

        if ( ! $keyed ) {
            $meta                       = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
            $paypal                     = is_array( $meta['paypal'] ?? null ) ? $meta['paypal'] : [];
            $paypal['request_order_id'] = $order->id();

            $this->saveCartMeta( $cart, $paypal );
        }

        return $this->sessionFromOrder( $order, $this->cartTotal( $cart ) );
    }

    /**
     * Loads a PayPal Order as a session (spec §7.2), with a `GET` only.
     *
     * The session carries the Order's amount, its normalized status, the
     * `payer-action` link, and, in `metadata`, the PayPal status, intent,
     * payment source, 3-D Secure `liability_shift`, `saved_card` (`yes`
     * when a saved card pays), and the capture (`charge`) or authorization
     * id when there is one. A card Order the buyer's browser captured
     * without the liability shift the policy needs is reported
     * `processing`, not `succeeded`, so the engine still calls
     * {@see self::capturePayment()}, which refunds and refuses it.
     *
     * @since 1.0.0
     *
     * @param  string  $reference  PayPal Order id.
     *
     * @throws PayPalApiException       When PayPal has no such Order (`404`) or can't be reached (retryable).
     * @throws UnexpectedValueException When the Order has no amount.
     *
     * @return PaymentSession
     */
    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        return $this->sessionFromOrder( $this->client->orders()->get( $reference ) );
    }

    /**
     * Captures the payment for `$session` (spec §7.3).
     *
     * - Intent `CAPTURE`: captures the Order (`capture-{order id}`).
     * - Intent `AUTHORIZE`: authorizes the Order (`authorize-{order id}`),
     *   records the authorization, then captures it with `final_capture`.
     *
     * An Order that is already captured returns its existing capture, so a
     * second call never moves money twice. Card and wallet payments whose
     * 3-D Secure result doesn't shift liability are refused when
     * `methods.card.require_liability_shift` is on
     * ({@see self::rejectsLiabilityShift()}); one the buyer's browser
     * already captured is refunded in full, with a
     * `paypal.liability_shift_refunded` timeline entry. Declines return a
     * failed result ({@see PayPalDeclines::payment()}); a `PENDING` capture
     * succeeds and is settled by webhook. The capture id, authorization,
     * and payer details go to `orders.meta.paypal`.
     *
     * Before anything moves, the PayPal Order is checked against the order:
     * an Order created for another cart (its `custom_id`) fails with
     * `paypal_order_mismatch`, and one paid with a source this gateway
     * doesn't take ({@see self::acceptedSources()}) with
     * `payment_source_mismatch`. An approved Order whose shipping address
     * differs from the order's is updated first
     * ({@see self::syncShippingAddress()}).
     *
     * @since 1.0.0
     *
     * @param  Order           $order    Order.
     * @param  PaymentSession  $session  Session.
     *
     * @throws PaymentCurrencyMismatchException When the session isn't in the order's currency.
     *
     * @return PaymentResult
     */
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        $this->assertCurrency( $order, $session->amount );

        try {
            $paypalOrder = $this->client->orders()->get( $session->reference );
        } catch ( PayPalApiException $exception ) {
            return PayPalDeclines::payment( $exception, $session->amount );
        }

        $mismatch = $this->cartMismatch( $order, $paypalOrder, $session );

        if ( null !== $mismatch ) {
            return $mismatch;
        }

        if ( null !== $paypalOrder->latestCapture() ) {
            $refusal = $this->liabilityShiftRefusal( $paypalOrder, $session );

            if ( null !== $refusal ) {
                $this->refundRefusedCapture( $order, $paypalOrder );

                return $refusal;
            }

            return $this->resultFromOrder( $order, $paypalOrder, $session );
        }

        $source = $paypalOrder->paymentSourceType();

        if ( null !== $source && ! in_array( $source, $this->acceptedSources(), true ) ) {
            return PaymentResult::terminalFailure( $session->amount, 'payment_source_mismatch', __( 'This payment method can\'t be used here. Choose another way to pay.' ) );
        }

        $authorizing = 'AUTHORIZE' === $paypalOrder->intent();
        $ready       = 'APPROVED' === $paypalOrder->status()
            || ( $authorizing && 'COMPLETED' === $paypalOrder->status() && null !== $paypalOrder->latestAuthorization() );

        if ( ! $ready ) {
            return $this->notReady( $paypalOrder, $session );
        }

        $refusal = $this->liabilityShiftRefusal( $paypalOrder, $session );

        if ( null !== $refusal ) {
            return $refusal;
        }

        if ( 'APPROVED' === $paypalOrder->status() ) {
            $patchFailure = $this->syncShippingAddress( $order, $paypalOrder, $session );

            if ( null !== $patchFailure ) {
                return $patchFailure;
            }
        }

        return $authorizing
            ? $this->captureAuthorized( $order, $paypalOrder, $session )
            : $this->captureOrder( $order, $paypalOrder, $session );
    }

    /**
     * Voids the order's uncaptured authorization (spec §7.4).
     *
     * A no-op when nothing is authorized (intent `CAPTURE`, or an Order that
     * was never approved), and when the authorization or Order is already
     * voided, expired, denied, or gone, so a retried void is harmless.
     * Throws when the authorization was captured (it needs a refund), when
     * PayPal refuses or can't be reached, and when the result doesn't
     * confirm `VOIDED` (engine contract).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @throws PayPalApiException When PayPal refuses the void or can't be reached.
     * @throws RuntimeException   When the payment was captured, or the void is unconfirmed.
     *
     * @return void
     */
    public function voidPendingPayment( Order $order ): void
    {
        $authorizationId = $this->authorizationIdFor( $order );

        if ( null === $authorizationId ) {
            return;
        }

        try {
            $authorization = $this->client->payments()->getAuthorization( $authorizationId );
        } catch ( PayPalApiException $exception ) {
            if ( 404 === $exception->status ) {
                $this->recordAuthorizationStatus( $order, $authorizationId, 'NOT_FOUND' );

                return;
            }

            throw $exception;
        }

        $status = (string) $authorization->status();

        if ( in_array( $status, [ 'VOIDED', 'EXPIRED', 'DENIED' ], true ) ) {
            $this->recordAuthorization( $order, $authorization );

            return;
        }

        if ( in_array( $status, [ 'CAPTURED', 'PARTIALLY_CAPTURED' ], true ) ) {
            throw new RuntimeException( __( 'PayPal authorization :id was already captured; refund it instead of voiding it.', [ 'id' => $authorizationId ] ) );
        }

        if ( ! in_array( $status, [ 'CREATED', 'PENDING' ], true ) ) {
            throw new RuntimeException( __( 'PayPal authorization :id is in an unexpected state (:status), so it was not voided.', [ 'id' => $authorizationId, 'status' => '' === $status ? '?' : $status ] ) );
        }

        try {
            $voided = $this->client->payments()->voidAuthorization( $authorizationId, 'void-' . $authorizationId )
                ?? $this->client->payments()->getAuthorization( $authorizationId );
        } catch ( PayPalApiException $exception ) {
            $settled = match ( true ) {
                404 === $exception->status                                                                             => 'NOT_FOUND',
                $exception->hasIssue( 'AUTHORIZATION_EXPIRED' )                                                        => 'EXPIRED',
                $exception->hasIssue( 'AUTHORIZATION_VOIDED' ), $exception->hasIssue( 'AUTHORIZATION_ALREADY_VOIDED' ) => 'VOIDED',
                default                                                                                                => null,
            };

            if ( null !== $settled ) {
                $this->recordAuthorizationStatus( $order, $authorizationId, $settled );

                return;
            }

            if ( $exception->hasIssue( 'AUTHORIZATION_ALREADY_CAPTURED' ) || $exception->hasIssue( 'PREVIOUSLY_CAPTURED' ) ) {
                throw new RuntimeException( __( 'PayPal authorization :id was already captured; refund it instead of voiding it.', [ 'id' => $authorizationId ] ), 0, $exception );
            }

            throw $exception;
        }

        if ( 'VOIDED' !== $voided->status() ) {
            throw new RuntimeException( __( 'PayPal did not confirm that authorization :id was voided (status: :status).', [ 'id' => $authorizationId, 'status' => (string) ( $voided->status() ?? '?' ) ] ) );
        }

        $this->recordAuthorization( $order, $voided );
    }

    /**
     * Refunds `$amount` of the order's capture (spec §7.5).
     *
     * Sent with `PayPal-Request-Id` set to `$context['idempotency_key']`,
     * `invoice_id` set to `refund-{refund_id}`, `custom_id` set to the engine
     * refund id (so webhooks match back), and the reason as `note_to_payer`
     * (PayPal shows it to the buyer, so the engine's reason codes are mapped
     * first: {@see self::noteToPayer()}). Declines return a failed result
     * with a translated message. A `PENDING` refund succeeds;
     * `PAYMENT.CAPTURE.REFUNDED` confirms it.
     *
     * An amount PayPal can't take exactly (a HUF or TWD amount with a
     * fraction) fails with `amount_not_representable` before PayPal is
     * called: PayPal would round it and refund a different amount.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order.
     * @param  Money                 $amount   Amount, in the order's currency.
     * @param  string|null           $reason   Reason shown to the payer.
     * @param  array<string, mixed>  $context  `idempotency_key` and `refund_id`.
     *
     * @throws PaymentCurrencyMismatchException When `$amount` isn't in the order's currency.
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        $this->assertCurrency( $order, $amount );

        if ( ! PayPalMoney::isExact( $amount ) ) {
            return RefundResult::failure(
                $amount,
                'amount_not_representable',
                __( 'PayPal only refunds whole :currency amounts.', [ 'currency' => $amount->getCurrency()->getCode() ] ),
            );
        }

        $captureId = $this->captureIdFor( $order, $amount );

        if ( $captureId instanceof RefundResult ) {
            return $captureId;
        }

        $refundId  = isset( $context['refund_id'] ) && '' !== (string) $context['refund_id'] ? (string) $context['refund_id'] : null;
        $requestId = isset( $context['idempotency_key'] ) && '' !== trim( (string) $context['idempotency_key'] )
            ? (string) $context['idempotency_key']
            : sprintf( 'refund-%s-%d-%s', $captureId, Refund::query()->where( 'order_id', $order->getKey() )->count() + 1, $amount->getAmount() );
        $reason    = self::noteToPayer( $reason );

        try {
            $refund = $this->client->payments()->refundCapture(
                $captureId,
                $requestId,
                PayPalMoney::toPayPal( $amount ),
                null === $refundId ? null : 'refund-' . $refundId,
                $reason,
                $refundId,
            );
        } catch ( PayPalApiException $exception ) {
            // No answer, or PayPal failed on its side: the refund may still
            // have gone through. Look for it before reporting a failure, or
            // a re-issued refund would pay out twice.
            if ( $exception->isRetryable() && null !== $refundId ) {
                $found = $this->findRefund( $order, $refundId );

                if ( null !== $found ) {
                    return $this->refundResult( $found, $amount );
                }
            }

            return PayPalDeclines::refund( $exception, $amount );
        }

        return $this->refundResult( $refund, $amount );
    }

    /**
     * Refuses webhooks: PayPal posts every event to the `paypal` gateway's
     * endpoint, which reconciles this gateway's sessions too.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return WebhookResult
     */
    public function handleWebhook( Request $request ): WebhookResult
    {
        return WebhookResult::unverified(
            'use_paypal_endpoint',
            __( 'PayPal webhooks are received at the paypal endpoint (ecommerce/webhooks/paypal).' ),
        );
    }

    /**
     * Describes the browser-side payment step (spec §9.1, issue #14).
     *
     * Read by `resources/js/paypal-checkout.js` through the storefront
     * driver named by `driver`. It never carries a secret: the client id is
     * public, and the PayPal Order id (`client_secret`, also `options.order_id`)
     * is what the SDK's `createOrder` returns, because the Order was created
     * server-side in {@see self::createPaymentSession()}.
     *
     * `options` holds the SDK settings (currency, intent, locale, components,
     * enable/disable funding), the amount as PayPal writes it (for wallet
     * sheets and Pay Later messages), `restart` (the buyer's funding source
     * was declined at capture, so the buttons reopen PayPal on the same
     * Order), plus the driver's own options
     * ({@see self::driverOptions()}). `components` is the same for every
     * gateway in the family, so a checkout page that renders several of them
     * loads the SDK once. The options pass through
     * `ap.ecommerce.paypal.clientOptions` `(array $options, Cart $cart,
     * PaymentSession $session, PayPalGateway $gateway)`; the engine's
     * `ap.ecommerce.payment.clientConfig` filter then sees the whole config.
     *
     * For gateways that vault (spec §10.2), `options.vault` tells the driver
     * whether to offer "save" (`available`) and what the shopper chose
     * (`save`), and a returning buyer gets a freshly minted `user_id_token`
     * ({@see self::vaultOptions()}).
     *
     * @since 1.0.0
     *
     * @param  Cart            $cart     Cart being paid for.
     * @param  PaymentSession  $session  Session.
     *
     * @return array{driver: string, flow: string, publishable_key?: string|null, client_secret?: string|null, redirect_url?: string|null, options: array<string, mixed>}
     */
    public function clientConfig( Cart $cart, PaymentSession $session ): array
    {
        $sdk    = self::sdkConfig( $session->amount->getCurrency()->getCode() );
        $config = [
            'driver'          => $this->driver(),
            'flow'            => 'embedded',
            'publishable_key' => $sdk['publishable_key'],
            'client_secret'   => $session->reference,
            'redirect_url'    => $session->redirectUrl,
            'options'         => array_merge( [
                'order_id' => $session->reference,
                'amount'   => PayPalMoney::toPayPal( $session->amount )['value'],
                'restart'  => $this->wasDeclined( $session->reference ),
            ], $sdk['options'], $this->driverOptions() ),
        ];

        $config['options'] = array_merge( $config['options'], $this->vaultOptions( $cart, (bool) $config['options']['restart'] ) );

        $filtered = applyFilters( self::CLIENT_OPTIONS_HOOK, $config['options'], $cart, $session, $this );

        if ( ! is_array( $filtered ) ) {
            return $config;
        }

        // The filter may add or change options (button style, a user id
        // token), never swap the Order out from under the checkout: the SDK
        // reads the Order, amount, currency, and intent from `options`, so
        // those stay the session's. The driver, client id, and Order id
        // outside `options` aren't passed to the filter at all.
        foreach ( [ 'order_id', 'amount', 'currency', 'intent' ] as $key ) {
            $filtered[ $key ] = $config['options'][ $key ];
        }

        $config['options'] = $filtered;

        return $config;
    }

    /**
     * Whether a card or wallet payment whose 3-D Secure result is `$shift`
     * must be refused: `methods.card.require_liability_shift` is on and the
     * result is `NO` or `UNKNOWN` (spec §9.4). With
     * `methods.card.three_d_secure` set to `SCA_ALWAYS`, a card (`card`
     * source) with no result at all is refused too, unless it is a saved
     * card paying (`$vaulted`). PayPal-wallet payments are never refused
     * here.
     *
     * Apple Pay and Google Pay aren't held to `SCA_ALWAYS`: the setting is
     * only sent to PayPal for card fields, so a wallet with no result
     * didn't skip a check it was asked for.
     *
     * @since 1.0.0
     *
     * @param  string|null  $source   PayPal payment source.
     * @param  string|null  $shift    `authentication_result.liability_shift`.
     * @param  bool         $vaulted  Whether a saved card pays (`vault_id` or `stored_credential`).
     *
     * @return bool
     */
    public static function rejectsLiabilityShift( ?string $source, ?string $shift, bool $vaulted = false ): bool
    {
        if ( ! in_array( $source, self::CARD_SOURCES, true ) || ! (bool) config( 'artisanpack.ecommerce-paypal.methods.card.require_liability_shift', true ) ) {
            return false;
        }

        $shift = strtoupper( trim( (string) $shift ) );

        if ( in_array( $shift, [ 'NO', 'UNKNOWN' ], true ) ) {
            return true;
        }

        // `SCA_ALWAYS` asks PayPal to authenticate every card, so a card
        // with no result skipped it. Saved cards are exempt: a customer-
        // initiated vaulted payment runs no 3-D Secure.
        return '' === $shift
            && 'card' === $source
            && ! $vaulted
            && 'SCA_ALWAYS' === strtoupper( (string) config( 'artisanpack.ecommerce-paypal.methods.card.three_d_secure', 'SCA_WHEN_REQUIRED' ) );
    }

    /**
     * The message shown when a card's 3-D Secure result is refused.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function liabilityShiftMessage(): string
    {
        return __( 'Your bank couldn\'t verify this card. Try another card or payment method.' );
    }

    /**
     * The public settings the JS SDK is loaded with for `$currency`: the
     * client id, plus currency, intent, mode, locale, components, and
     * funding. Every PayPal surface on a page (the checkout drivers, Pay
     * Later messages) uses these, so `paypal-checkout.js` loads the SDK once.
     *
     * @since 1.0.0
     *
     * @param  string  $currency  ISO currency code.
     *
     * @return array{publishable_key: string|null, options: array{currency: string, intent: string, mode: string, locale: string, components: array<int, string>, enable_funding: array<int, string>, disable_funding: array<int, string>}}
     */
    public static function sdkConfig( string $currency ): array
    {
        $client = app( PayPalClient::class );

        return [
            'publishable_key' => $client->credentials()->clientId,
            'options'         => [
                'currency'        => strtoupper( $currency ),
                'intent'          => self::sdkIntent(),
                'mode'            => $client->environment()->mode,
                'locale'          => str_replace( '-', '_', PayPalLocale::resolve() ),
                'components'      => self::sdkComponents(),
                'enable_funding'  => self::enabledFunding(),
                'disable_funding' => self::disabledFunding(),
            ],
        ];
    }

    /**
     * The JS SDK components every gateway in the family loads, from the
     * enabled methods and Pay Later messaging placements: `buttons`,
     * `messages`, `card-fields`, `applepay`, `googlepay`.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function sdkComponents(): array
    {
        $methods    = (array) config( 'artisanpack.ecommerce-paypal.methods', [] );
        $on         = static fn ( string $method ): bool => (bool) data_get( $methods, $method . '.enabled', false );
        $messaging  = array_filter( (array) config( 'artisanpack.ecommerce-paypal.pay_later_messaging', [] ) );
        $components = [];

        if ( $on( 'paypal' ) ) {
            $components[] = 'buttons';
        }

        if ( ( $on( 'paypal' ) && (bool) data_get( $methods, 'paypal.pay_later', false ) ) || [] !== $messaging ) {
            $components[] = 'messages';
        }

        foreach ( [ 'card' => 'card-fields', 'apple_pay' => 'applepay', 'google_pay' => 'googlepay' ] as $method => $component ) {
            if ( $on( $method ) ) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * The SDK `intent` (`capture` or `authorize`).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function sdkIntent(): string
    {
        return 'authorize' === strtolower( (string) config( 'artisanpack.ecommerce-paypal.intent', 'capture' ) ) ? 'authorize' : 'capture';
    }

    /**
     * Funding sources the SDK should offer beyond its defaults (`venmo`,
     * `paylater`).
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function enabledFunding(): array
    {
        return array_keys( array_filter( [
            'venmo'    => (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.venmo', false ),
            'paylater' => (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.pay_later', false ),
        ] ) );
    }

    /**
     * Funding sources the PayPal buttons must not show: Venmo and Pay Later
     * when switched off, and the card button while cards have their own
     * checkout choice (`paypal-card`, spec D3).
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public static function disabledFunding(): array
    {
        return array_keys( array_filter( [
            'venmo'    => ! (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.venmo', false ),
            'paylater' => ! (bool) config( 'artisanpack.ecommerce-paypal.methods.paypal.pay_later', false ),
            'card'     => (bool) config( 'artisanpack.ecommerce-paypal.methods.card.enabled', false ),
        ] ) );
    }

    /**
     * The "save" choice and saved card id that `$options` ask for.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $options  Validated options.
     *
     * @return array{0: bool, 1: int|null}
     */
    protected static function requestedOptions( array $options ): array
    {
        $saved = $options[ self::SAVED_METHOD_OPTION ] ?? null;

        return [
            true === filter_var( $options[ self::SAVE_OPTION ] ?? false, FILTER_VALIDATE_BOOLEAN ),
            is_numeric( $saved ) ? (int) $saved : null,
        ];
    }

    /**
     * The refund's `note_to_payer`, which PayPal shows to the buyer: the
     * engine's reason codes for automatic refunds become a sentence
     * (`requested_by_customer`) or nothing (`fraudulent`, `duplicate`);
     * free text passes through trimmed. Null when there is nothing to say.
     *
     * @since 1.0.0
     *
     * @param  string|null  $reason  Refund reason.
     *
     * @return string|null
     */
    protected static function noteToPayer( ?string $reason ): ?string
    {
        $reason = null === $reason ? null : trim( $reason );

        $reason = match ( $reason ) {
            'fraudulent', 'duplicate' => null,
            'requested_by_customer'   => __( 'Refund requested by the customer.' ),
            default                   => $reason,
        };

        return null === $reason || '' === $reason ? null : $reason;
    }

    /**
     * Whether the buyer's funding source was declined when PayPal Order
     * `$paypalOrderId` was captured, so the driver reopens PayPal on the same
     * Order for them to pick another (spec §7.3, §9.3).
     *
     * @since 1.0.0
     *
     * @param  string  $paypalOrderId  PayPal Order id.
     *
     * @return bool
     */
    protected function wasDeclined( string $paypalOrderId ): bool
    {
        $order = app( PayPalOrderLocator::class )->forPayPalOrder( $paypalOrderId );

        return null !== $order
            && PayPalOrderMeta::describes( $order, $paypalOrderId )
            && (int) PayPalOrderMeta::get( $order, 'instrument_declines', 0 ) > 0
            && null === PayPalOrderMeta::get( $order, 'capture_id' );
    }

    /**
     * The vault options of the client config: `vault.available` (vaulting
     * is on for this gateway, the shopper is signed in and owns the cart,
     * and the buyer isn't being sent back after a decline), `vault.save`
     * (they ticked "save"), and a `user_id_token` when they already have a
     * PayPal customer id. Empty for gateways that don't vault.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart        Cart.
     * @param  bool  $restarting  Whether the buyer is retrying after a decline.
     *
     * @return array<string, mixed>
     */
    protected function vaultOptions( Cart $cart, bool $restarting ): array
    {
        if ( ! $this->supportsSavedInstruments() ) {
            return [];
        }

        $vault    = app( PayPalVault::class );
        $customer = $vault->shopperFor( $cart );
        $options  = [
            'vault' => [
                'available' => null !== $customer && ! $restarting,
                'save'      => null !== $customer && $vault->wantsToSave( $cart ),
            ],
        ];

        $idToken = null === $customer ? null : $vault->idTokenFor( $customer );

        if ( null !== $idToken ) {
            $options['user_id_token'] = $idToken;
        }

        return $options;
    }

    /**
     * Options only this gateway's driver reads (button style, 3-D Secure).
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function driverOptions(): array
    {
        return [];
    }

    /**
     * The engine result for a refund PayPal returned.
     *
     * `COMPLETED` and `PENDING` succeed with the amount PayPal refunded (so
     * the engine's amount check sees what actually moved); `FAILED` and
     * `CANCELLED` fail.
     *
     * @since 1.0.0
     *
     * @param  PayPalRefund  $refund  Refund.
     * @param  Money         $amount  Amount requested.
     *
     * @return RefundResult
     */
    protected function refundResult( PayPalRefund $refund, Money $amount ): RefundResult
    {
        $status = (string) $refund->status();

        if ( in_array( $status, [ 'FAILED', 'CANCELLED' ], true ) ) {
            $code = 'refund_' . strtolower( $status );

            return RefundResult::failure( $amount, $code, PayPalDeclines::refundMessage( $code ) );
        }

        $refunded = $amount;

        if ( null !== $refund->amount() ) {
            try {
                $refunded = PayPalMoney::fromPayPal( $refund->amount() );
            } catch ( InvalidArgumentException ) {
                $refunded = $amount;
            }
        }

        return RefundResult::success( $refunded, '' !== $refund->id() ? $refund->id() : null );
    }

    /**
     * The refund PayPal holds for engine refund `$refundId` (matched on
     * `custom_id` or `invoice_id`), or null when there is none or PayPal
     * can't be asked.
     *
     * @since 1.0.0
     *
     * @param  Order   $order     Engine order.
     * @param  string  $refundId  Engine refund id.
     *
     * @return PayPalRefund|null
     */
    protected function findRefund( Order $order, string $refundId ): ?PayPalRefund
    {
        $reference = (string) ( $order->getAttribute( 'payment_reference' ) ?? '' );

        if ( '' === $reference ) {
            return null;
        }

        $paypalOrder = $this->refetch( $reference );

        foreach ( (array) $paypalOrder?->get( 'purchase_units.0.payments.refunds', [] ) as $refund ) {
            if ( ! is_array( $refund ) ) {
                continue;
            }

            if ( $refundId === (string) ( $refund['custom_id'] ?? '' ) || 'refund-' . $refundId === (string) ( $refund['invoice_id'] ?? '' ) ) {
                return PayPalRefund::fromArray( $refund );
            }
        }

        return null;
    }

    /**
     * The status of a `COMPLETED` Order, from its latest capture or
     * authorization.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $order  PayPal Order.
     *
     * @return string|null
     */
    protected function completedStatus( PayPalOrder $order ): ?string
    {
        $capture = $order->latestCapture();

        if ( null !== $capture ) {
            return match ( $capture->status() ) {
                'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' => PaymentSession::STATUS_SUCCEEDED,
                'PENDING'                                     => PaymentSession::STATUS_PROCESSING,
                'DECLINED', 'FAILED'                          => PaymentSession::STATUS_REQUIRES_PAYMENT_METHOD,
                default                                       => null,
            };
        }

        $authorization = $order->latestAuthorization();

        if ( null !== $authorization ) {
            return match ( $authorization->status() ) {
                'CREATED', 'PARTIALLY_CAPTURED', 'PENDING' => PaymentSession::STATUS_AUTHORIZED,
                'CAPTURED'                                 => PaymentSession::STATUS_SUCCEEDED,
                'VOIDED', 'EXPIRED', 'DENIED'              => PaymentSession::STATUS_CANCELED,
                default                                    => null,
            };
        }

        return null;
    }

    /**
     * A PayPal Order as an engine session.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $order     PayPal Order.
     * @param  Money|null   $fallback  Amount to use when PayPal's answer has none.
     *
     * @throws UnexpectedValueException When the Order has no amount and there is no fallback.
     *
     * @return PaymentSession
     */
    protected function sessionFromOrder( PayPalOrder $order, ?Money $fallback = null ): PaymentSession
    {
        $amount = null !== $order->amount() ? PayPalMoney::fromPayPal( $order->amount() ) : $fallback;

        if ( null === $amount ) {
            throw new UnexpectedValueException( sprintf( 'PayPal order %s has no amount.', $order->id() ) );
        }

        $metadata = array_filter( [
            'paypal_status'   => $order->status(),
            'intent'          => null === $order->intent() ? null : strtolower( $order->intent() ),
            'payment_source'  => $order->paymentSourceType(),
            'liability_shift' => $order->liabilityShift(),
            'saved_card'      => self::paysWithSavedCard( $order ) ? 'yes' : null,
            'charge'          => $order->latestCapture()?->id(),
            'authorization'   => $order->latestAuthorization()?->id(),
        ], static fn ( ?string $value ): bool => null !== $value && '' !== $value );

        $status = $this->sessionStatus( $order );

        // A card Order the buyer's browser captured without the 3-D Secure
        // result the policy needs isn't reported as paid: the engine then
        // calls capturePayment(), which refunds and refuses it.
        if ( PaymentSession::STATUS_SUCCEEDED === $status && null !== $order->latestCapture() && self::rejectsOrderLiabilityShift( $order ) ) {
            $status = PaymentSession::STATUS_PROCESSING;
        }

        return new PaymentSession(
            gatewayKey: $this->key(),
            reference: $order->id(),
            amount: $amount,
            redirectUrl: $order->payerActionUrl(),
            metadata: $metadata,
            status: $status,
        );
    }

    /**
     * `$context` with its payment options applied (#42): the "save" choice
     * is recorded on `cart.meta.paypal` (a missing choice means "don't
     * save"), and a saved card becomes {@see PayPalVault::SAVED_TOKEN_CONTEXT},
     * which the payload builder checks belongs to the signed-in shopper.
     * Context without options is returned unchanged.
     *
     * The engine creates the session inside a database transaction, so a
     * failure rolls the recorded choice back with it.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  PayPalVault           $vault    Vault.
     * @param  array<string, mixed>  $context  Session context.
     *
     * @return array<string, mixed>
     */
    protected function withPaymentOptions( Cart $cart, PayPalVault $vault, array $context ): array
    {
        $options = $context[ CheckoutService::PAYMENT_OPTIONS ] ?? null;

        if ( ! is_array( $options ) ) {
            return $context;
        }

        [ $save, $savedCard ] = self::requestedOptions( $options );

        if ( $vault->wantsToSave( $cart ) !== $save ) {
            $vault->rememberChoice( $cart, $save );
        }

        if ( null !== $savedCard ) {
            $context[ PayPalVault::SAVED_TOKEN_CONTEXT ] = $savedCard;
        }

        return $context;
    }

    /**
     * Soft-deletes `$token` again once the request ends. The engine creates
     * sessions inside a database transaction, which rolls back the delete
     * made when PayPal rejected the token, so without this the shopper
     * would be offered the dead card again.
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken  $token  The token PayPal rejected.
     *
     * @return void
     */
    protected function forgetAfterRequest( PayPalPaymentToken $token ): void
    {
        $id = $token->getKey();

        app()->terminating( static function () use ( $id ): void {
            $current = PayPalPaymentToken::query()->whereKey( $id )->first();

            if ( null !== $current ) {
                app( PayPalVault::class )->forget( $current );
            }
        } );
    }

    /**
     * What the saved card in `$context` adds to the cart's request id
     * fingerprint (`saved-{token id}`), or an empty string without one. The
     * payload builder refuses a token that can't pay, so a built Order
     * always pays with the card named here.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $context  Session context.
     *
     * @return string
     */
    protected static function savedCardVariant( array $context ): string
    {
        $tokenId = $context[ PayPalVault::SAVED_TOKEN_CONTEXT ] ?? null;

        return ( is_int( $tokenId ) || is_string( $tokenId ) ) && '' !== (string) $tokenId ? 'saved-' . $tokenId : '';
    }

    /**
     * The saved card the built payload pays with, or null.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $payload  Built Order body.
     * @param  array<string, mixed>  $context  Session context.
     *
     * @return PayPalPaymentToken|null
     */
    protected function savedCardIn( Cart $cart, array $payload, array $context ): ?PayPalPaymentToken
    {
        $tokenId = $context[ PayPalVault::SAVED_TOKEN_CONTEXT ] ?? null;

        if ( ! isset( $payload['payment_source']['card']['vault_id'] ) || ( ! is_int( $tokenId ) && ! is_string( $tokenId ) ) ) {
            return null;
        }

        $token = app( PayPalVault::class )->savedCardFor( $cart, $tokenId );

        return null !== $token && $token->paypal_token_id === $payload['payment_source']['card']['vault_id'] ? $token : null;
    }

    /**
     * The `PayPal-Request-Id` for creating an Order when the engine passed
     * no idempotency key: a UUID kept on `cart.meta.paypal`.
     *
     * The id is reused only while the cart's gateway, currency, total, and
     * the shopper's "save" choice (and saved card, `$variant`) are
     * unchanged **and** it hasn't produced an Order yet, so a create that
     * failed before PayPal answered is retried with the same id. Once an
     * Order is recorded for it (`request_order_id`), any new create means
     * the engine judged that Order unusable (it reset the payment, or the
     * shopper switched gateways and back), so a new id is minted.
     *
     * The engine creates sessions inside a database transaction, which
     * rolls the stored id back along with a failed create; the reuse only
     * matters to callers that create sessions outside one.
     *
     * @since 1.0.0
     *
     * @param  Cart    $cart     Cart.
     * @param  string  $variant  What else makes the Order different (the saved card paying).
     *
     * @return string
     */
    protected function cartRequestId( Cart $cart, string $variant = '' ): string
    {
        $meta        = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
        $paypal      = is_array( $meta['paypal'] ?? null ) ? $meta['paypal'] : [];
        $fingerprint = hash( 'sha256', implode( '|', [
            $this->key(),
            strtoupper( (string) ( $cart->getAttribute( 'currency' ) ?: $cart->getAttribute( 'total_currency' ) ?: '' ) ),
            (string) ( $cart->getAttribute( 'total_amount' ) ?? '' ),
            app( PayPalVault::class )->wantsToSave( $cart ) ? 'save' : '',
            $variant,
        ] ) );
        $stored      = isset( $paypal['request_id'] ) && is_string( $paypal['request_id'] ) ? $paypal['request_id'] : '';
        $usedBy      = isset( $paypal['request_order_id'] ) ? (string) $paypal['request_order_id'] : '';

        if ( '' !== $stored && $fingerprint === ( $paypal['request_fingerprint'] ?? null ) && '' === $usedBy ) {
            return $stored;
        }

        $paypal['request_id']          = (string) Str::uuid();
        $paypal['request_fingerprint'] = $fingerprint;
        unset( $paypal['request_order_id'] );

        $this->saveCartMeta( $cart, $paypal );

        return $paypal['request_id'];
    }

    /**
     * Writes `cart.meta.paypal`, saving the cart when it is stored.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart    Cart.
     * @param  array<string, mixed>  $paypal  The PayPal namespace.
     *
     * @return void
     */
    protected function saveCartMeta( Cart $cart, array $paypal ): void
    {
        $meta           = (array) ( $cart->getAttribute( 'meta' ) ?? [] );
        $meta['paypal'] = $paypal;

        $cart->setAttribute( 'meta', $meta );

        if ( $cart->exists ) {
            $cart->save();
        }
    }

    /**
     * The cart's total as engine money, or null when it has no currency.
     *
     * @since 1.0.0
     *
     * @param  Cart  $cart  Cart.
     *
     * @return Money|null
     */
    protected function cartTotal( Cart $cart ): ?Money
    {
        $currency = strtoupper( (string) ( $cart->getAttribute( 'currency' ) ?: $cart->getAttribute( 'total_currency' ) ?: '' ) );

        return '' === $currency ? null : new Money( (int) ( $cart->getAttribute( 'total_amount' ) ?? 0 ), new Currency( $currency ) );
    }

    /**
     * The failure for an Order that can't be captured yet.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder     $order    PayPal Order.
     * @param  PaymentSession  $session  Session.
     *
     * @return PaymentResult
     */
    protected function notReady( PayPalOrder $order, PaymentSession $session ): PaymentResult
    {
        $status = (string) $order->status();

        return match ( $status ) {
            'PAYER_ACTION_REQUIRED' => PaymentResult::retryableFailure( $session->amount, 'requires_action', __( 'PayPal needs you to complete another step before this payment can be taken.' ) ),
            'VOIDED'                => PaymentResult::terminalFailure( $session->amount, 'paypal_order_voided', __( 'This PayPal payment was cancelled. Start the payment again.' ) ),
            'CREATED', 'SAVED'      => PaymentResult::terminalFailure( $session->amount, 'payment_not_approved', __( 'The payment has not been approved in PayPal yet.' ) ),
            default                 => PaymentResult::retryableFailure( $session->amount, 'paypal_order_' . strtolower( '' === $status ? 'unknown' : $status ), __( 'PayPal is still processing this payment. Try again in a moment.' ) ),
        };
    }

    /**
     * The refusal for a card or wallet payment whose 3-D Secure result
     * doesn't shift liability (`NO`, `UNKNOWN`) while
     * `methods.card.require_liability_shift` is on, or null to go ahead.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder     $order    PayPal Order.
     * @param  PaymentSession  $session  Session.
     *
     * @return PaymentResult|null
     */
    protected function liabilityShiftRefusal( PayPalOrder $order, PaymentSession $session ): ?PaymentResult
    {
        if ( ! self::rejectsOrderLiabilityShift( $order ) ) {
            return null;
        }

        return PaymentResult::terminalFailure(
            $session->amount,
            'liability_shift_rejected',
            self::liabilityShiftMessage(),
        );
    }

    /**
     * Whether `$order`'s card or wallet payment must be refused under the
     * liability-shift policy ({@see self::rejectsLiabilityShift()}).
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $order  PayPal Order.
     *
     * @return bool
     */
    protected static function rejectsOrderLiabilityShift( PayPalOrder $order ): bool
    {
        return self::rejectsLiabilityShift( $order->paymentSourceType(), $order->liabilityShift(), self::paysWithSavedCard( $order ) );
    }

    /**
     * Whether a saved card pays for `$order`: its card source carries a
     * `vault_id` or `stored_credential`.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $order  PayPal Order.
     *
     * @return bool
     */
    protected static function paysWithSavedCard( PayPalOrder $order ): bool
    {
        $card = $order->get( 'payment_source.card' );

        return is_array( $card ) && ( ! empty( $card['vault_id'] ) || ! empty( $card['stored_credential'] ) );
    }

    /**
     * Refunds the capture of an Order the buyer's browser captured although
     * the liability-shift policy refuses it, and writes a
     * `paypal.liability_shift_refunded` timeline entry (or
     * `paypal.liability_shift_refund_failed` when PayPal refuses). A capture
     * that is already refunded (or whose refund is already recorded) is
     * left alone, so a retry neither refunds nor records it twice.
     *
     * @since 1.0.0
     *
     * @param  Order        $order        Engine order.
     * @param  PayPalOrder  $paypalOrder  The captured PayPal Order.
     *
     * @return void
     */
    protected function refundRefusedCapture( Order $order, PayPalOrder $paypalOrder ): void
    {
        $capture = $paypalOrder->latestCapture();

        if ( null === $capture || '' === $capture->id() || ! in_array( $capture->status(), [ 'COMPLETED', 'PENDING' ], true ) ) {
            return;
        }

        $alreadyRefunded = OrderTimelineEntry::query()
            ->where( 'order_id', $order->getKey() )
            ->where( 'event_type', 'paypal.liability_shift_refunded' )
            ->exists();

        if ( $alreadyRefunded ) {
            return;
        }

        try {
            $refund = $this->client->payments()->refundCapture(
                $capture->id(),
                'liability-refund-' . $capture->id(),
                $capture->amount(),
                null,
                self::liabilityShiftMessage(),
            );
        } catch ( PayPalApiException $exception ) {
            report( $exception );

            $this->timeline( $order, 'paypal.liability_shift_refund_failed', [
                'order_id'   => $paypalOrder->id(),
                'capture_id' => $capture->id(),
                'error'      => $exception->issue() ?? $exception->name,
                'debug_id'   => $exception->debugId,
            ] );

            return;
        }

        $this->timeline( $order, 'paypal.liability_shift_refunded', [
            'order_id'        => $paypalOrder->id(),
            'capture_id'      => $capture->id(),
            'refund_id'       => '' === $refund->id() ? null : $refund->id(),
            'refund_status'   => $refund->status(),
            'liability_shift' => $paypalOrder->liabilityShift(),
        ] );
    }

    /**
     * The failure for a PayPal Order that was created for another cart
     * than the one `$order` was placed from (its `custom_id`), or null when
     * they match or either side is unknown.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  PayPal Order.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult|null
     */
    protected function cartMismatch( Order $order, PayPalOrder $paypalOrder, PaymentSession $session ): ?PaymentResult
    {
        $customId = $paypalOrder->customId();

        if ( null === $customId || '' === $customId || null === $order->getKey() ) {
            return null;
        }

        $cartId = Cart::query()->where( 'completed_order_id', $order->getKey() )->value( 'id' );

        if ( null === $cartId || (string) $cartId === $customId ) {
            return null;
        }

        return PaymentResult::terminalFailure(
            $session->amount,
            'paypal_order_mismatch',
            __( 'This PayPal payment belongs to another checkout. Start the payment again.' ),
        );
    }

    /**
     * The PayPal payment sources this gateway captures. An Order paid with
     * any other source (a card confirmed on the PayPal gateway, a wallet on
     * the card gateway) is refused, so a method the store switched off
     * can't be used through a sibling.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    protected function acceptedSources(): array
    {
        return [ $this->paymentSource() ];
    }

    /**
     * Updates an approved PayPal Order's shipping address and recipient to
     * the order's, when they differ, before it is captured.
     *
     * The engine reuses a PayPal Order while the total stays the same, so a
     * shopper who changed the address afterwards would leave PayPal holding
     * the old one, which costs the merchant PayPal Seller Protection. Only
     * PayPal and Venmo Orders carry the address the store provided
     * (`SET_PROVIDED_ADDRESS`); an Order whose experience context says
     * otherwise is left alone.
     *
     * A PATCH PayPal refuses is logged and recorded as a
     * `paypal.shipping_patch_failed` timeline entry, and the payment still
     * goes ahead: the buyer approved the amount, and the address only
     * protects the merchant. A PATCH that can't reach PayPal returns a
     * retryable failure.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  Approved PayPal Order.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult|null Null to go ahead with the capture.
     */
    protected function syncShippingAddress( Order $order, PayPalOrder $paypalOrder, PaymentSession $session ): ?PaymentResult
    {
        $source = $paypalOrder->paymentSourceType();

        if ( ! in_array( $source, [ 'paypal', 'venmo' ], true ) ) {
            return null;
        }

        $preference = $paypalOrder->get( 'payment_source.' . $source . '.experience_context.shipping_preference' );

        if ( null !== $preference && 'SET_PROVIDED_ADDRESS' !== $preference ) {
            return null;
        }

        $current = $paypalOrder->get( 'purchase_units.0.shipping' );
        $wanted  = $this->payloads->shippingFor( (array) ( $order->getAttribute( 'shipping_address' ) ?? [] ) );

        if ( ! is_array( $current ) || ! is_array( $current['address'] ?? null ) || null === $wanted ) {
            return null;
        }

        $sameAddress = self::sameValues( $current['address'], $wanted['address'] );
        $sameName    = ! isset( $wanted['name'] ) || self::sameValues( (array) ( $current['name'] ?? [] ), $wanted['name'] );

        if ( $sameAddress && $sameName ) {
            return null;
        }

        $path       = "/purchase_units/@reference_id=='default'/shipping";
        $operations = [ [ 'op' => 'replace', 'path' => $path . '/address', 'value' => $wanted['address'] ] ];

        if ( isset( $wanted['name'] ) ) {
            $operations[] = [ 'op' => 'replace', 'path' => $path . '/name', 'value' => $wanted['name'] ];
        }

        try {
            $this->client->orders()->patch(
                $paypalOrder->id(),
                $operations,
                'patch-' . $paypalOrder->id() . '-' . substr( sha1( (string) json_encode( $wanted ) ), 0, 16 ),
            );
        } catch ( PayPalApiException $exception ) {
            if ( $exception->isRetryable() ) {
                return PayPalDeclines::payment( $exception, $session->amount );
            }

            Log::channel( self::logChannel() )->warning( 'Could not update the PayPal Order\'s shipping address before capture.', [
                'order'           => $order->getKey(),
                'paypal_order_id' => $paypalOrder->id(),
                ...$exception->context(),
            ] );

            $this->timeline( $order, 'paypal.shipping_patch_failed', [
                'order_id' => $paypalOrder->id(),
                'error'    => $exception->issue() ?? $exception->name,
                'debug_id' => $exception->debugId,
            ] );
        }

        return null;
    }

    /**
     * Whether two PayPal objects (an address, a name) hold the same
     * non-empty values, ignoring key order and case.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $left   One object.
     * @param  array<string, mixed>  $right  The other.
     *
     * @return bool
     */
    protected static function sameValues( array $left, array $right ): bool
    {
        $normalize = static function ( array $values ): array {
            $values = array_filter(
                array_map( static fn ( mixed $value ): string => is_scalar( $value ) ? mb_strtolower( trim( (string) $value ) ) : '', $values ),
                static fn ( string $value ): bool => '' !== $value,
            );

            ksort( $values );

            return $values;
        };

        return $normalize( $left ) === $normalize( $right );
    }

    /**
     * Writes an order timeline entry.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Engine order.
     * @param  string                $type     Event type (`paypal.shipping_patch_failed`, …).
     * @param  array<string, mixed>  $payload  Payload; null and empty values are dropped.
     *
     * @return void
     */
    protected function timeline( Order $order, string $type, array $payload ): void
    {
        OrderTimelineEntry::query()->create( [
            'order_id'      => $order->getKey(),
            'actor_user_id' => null,
            'event_type'    => $type,
            'payload'       => array_filter( $payload, static fn ( mixed $value ): bool => null !== $value && '' !== $value ),
        ] );
    }

    /**
     * The configured log channel, or null for the default.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected static function logChannel(): ?string
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return is_string( $channel ) && '' !== $channel ? $channel : null;
    }

    /**
     * Captures an approved `CAPTURE`-intent Order.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  Approved PayPal Order.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult
     */
    protected function captureOrder( Order $order, PayPalOrder $paypalOrder, PaymentSession $session ): PaymentResult
    {
        $declines  = PayPalOrderMeta::describes( $order, $paypalOrder->id() ) ? (int) PayPalOrderMeta::get( $order, 'instrument_declines', 0 ) : 0;
        $requestId = 'capture-' . $paypalOrder->id() . ( $declines > 0 ? '-' . ( $declines + 1 ) : '' );

        try {
            $captured = $this->client->orders()->capture( $paypalOrder->id(), $requestId );
        } catch ( PayPalApiException $exception ) {
            if ( $exception->hasIssue( 'ORDER_ALREADY_CAPTURED' ) ) {
                $current = $this->refetch( $paypalOrder->id() );

                if ( null !== $current?->latestCapture() ) {
                    return $this->resultFromOrder( $order, $current, $session );
                }
            }

            if ( $exception->hasIssue( 'INSTRUMENT_DECLINED' ) ) {
                // The buyer picks another funding source for the same Order
                // (PayPal's restart flow). The next capture needs a new
                // request id, or PayPal could answer with this decline again;
                // an Order is only ever captured once, so it can't charge twice.
                PayPalOrderMeta::merge( $order, [ 'instrument_declines' => $declines + 1 ], $paypalOrder->id() );
            }

            return PayPalDeclines::payment( $exception, $session->amount );
        }

        return $this->resultFromOrder( $order, $captured, $session );
    }

    /**
     * Authorizes an approved `AUTHORIZE`-intent Order (unless it already
     * is), records the authorization, and captures it in full.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  Approved or authorized PayPal Order.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult
     */
    protected function captureAuthorized( Order $order, PayPalOrder $paypalOrder, PaymentSession $session ): PaymentResult
    {
        if ( null === $paypalOrder->latestAuthorization() ) {
            $declines  = PayPalOrderMeta::describes( $order, $paypalOrder->id() ) ? (int) PayPalOrderMeta::get( $order, 'instrument_declines', 0 ) : 0;
            $requestId = 'authorize-' . $paypalOrder->id() . ( $declines > 0 ? '-' . ( $declines + 1 ) : '' );

            try {
                $paypalOrder = $this->client->orders()->authorize( $paypalOrder->id(), $requestId );
            } catch ( PayPalApiException $exception ) {
                $current = $exception->hasIssue( 'ORDER_ALREADY_AUTHORIZED' ) ? $this->refetch( $paypalOrder->id() ) : null;

                if ( null === $current?->latestAuthorization() ) {
                    if ( $exception->hasIssue( 'INSTRUMENT_DECLINED' ) ) {
                        // As in captureOrder(): the buyer picks another
                        // funding source for the same Order, and the retry
                        // needs a new request id.
                        PayPalOrderMeta::merge( $order, [ 'instrument_declines' => $declines + 1 ], $paypalOrder->id() );
                    }

                    return PayPalDeclines::payment( $exception, $session->amount );
                }

                $paypalOrder = $current;
            }
        }

        $authorization = $paypalOrder->latestAuthorization();

        if ( null === $authorization || '' === $authorization->id() ) {
            return PaymentResult::retryableFailure( $session->amount, 'authorization_missing', __( 'PayPal did not return the authorization. Try again in a moment.' ) );
        }

        $this->recordPayment( $order, $paypalOrder );

        $status = (string) $authorization->status();

        if ( in_array( $status, [ 'CAPTURED', 'PARTIALLY_CAPTURED' ], true ) ) {
            $current = $this->refetch( $paypalOrder->id() );

            return null === $current
                ? PaymentResult::retryableFailure( $session->amount, PayPalDeclines::PROVIDER_UNAVAILABLE, __( 'PayPal could not be reached. Try again in a moment.' ) )
                : $this->resultFromOrder( $order, $current, $session );
        }

        if ( in_array( $status, [ 'VOIDED', 'EXPIRED', 'DENIED' ], true ) ) {
            return PaymentResult::terminalFailure( $session->amount, 'authorization_' . strtolower( $status ), __( 'The PayPal authorization is no longer valid. Start the payment again.' ) );
        }

        if ( 'CREATED' !== $status ) {
            return PaymentResult::retryableFailure( $session->amount, 'authorization_' . strtolower( '' === $status ? 'unknown' : $status ), __( 'PayPal is still reviewing this payment. Try again later.' ) );
        }

        try {
            $capture = $this->client->payments()->captureAuthorization( $authorization->id(), 'capture-' . $authorization->id(), null, true );
        } catch ( PayPalApiException $exception ) {
            if ( $exception->hasIssue( 'AUTHORIZATION_ALREADY_CAPTURED' ) ) {
                $current = $this->refetch( $paypalOrder->id() );

                if ( null !== $current?->latestCapture() ) {
                    return $this->resultFromOrder( $order, $current, $session );
                }
            }

            return PayPalDeclines::payment( $exception, $session->amount );
        }

        return $this->resultFromCapture( $order, $paypalOrder, $capture, $session );
    }

    /**
     * The result for an Order that carries a capture.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  PayPal Order.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult
     */
    protected function resultFromOrder( Order $order, PayPalOrder $paypalOrder, PaymentSession $session ): PaymentResult
    {
        $capture = $paypalOrder->latestCapture();

        if ( null === $capture || '' === $capture->id() ) {
            return PaymentResult::retryableFailure( $session->amount, 'capture_missing', __( 'PayPal did not return the capture. Try again in a moment.' ) );
        }

        return $this->resultFromCapture( $order, $paypalOrder, $capture, $session );
    }

    /**
     * The result for a capture: `COMPLETED` (or since refunded) and
     * `PENDING` succeed, the capture id being the gateway reference;
     * `DECLINED` and `FAILED` are terminal.
     *
     * The first time a capture is recorded on the order, the saved card
     * that paid (if any) gets its `last_used_at` updated and
     * `ap.ecommerce.paypal.captured` fires; an idempotent retry that finds
     * the same capture does neither again. A listener that throws is
     * reported and never turns the capture into a failure.
     *
     * @since 1.0.0
     *
     * @param  Order           $order        Engine order.
     * @param  PayPalOrder     $paypalOrder  PayPal Order the capture belongs to.
     * @param  PayPalCapture   $capture      Capture.
     * @param  PaymentSession  $session      Session.
     *
     * @return PaymentResult
     */
    protected function resultFromCapture( Order $order, PayPalOrder $paypalOrder, PayPalCapture $capture, PaymentSession $session ): PaymentResult
    {
        $alreadyRecorded = PayPalOrderMeta::describes( $order, $paypalOrder->id() ) && $capture->id() === PayPalOrderMeta::get( $order, 'capture_id' );

        $this->recordPayment( $order, $paypalOrder, $capture );

        $status = (string) $capture->status();

        if ( in_array( $status, [ 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'PENDING' ], true ) ) {
            if ( ! $alreadyRecorded ) {
                $this->recordSavedCardUse( $paypalOrder );

                // The money has moved: a host listener must not fail the capture.
                try {
                    doAction( self::CAPTURED_HOOK, $order, $capture->id(), $status, $paypalOrder, $this );
                } catch ( Throwable $exception ) {
                    report( $exception );
                }
            }

            return PaymentResult::success( $this->capturedAmount( $capture, $session ), $capture->id() );
        }

        if ( in_array( $status, [ 'DECLINED', 'FAILED' ], true ) ) {
            return PaymentResult::terminalFailure( $session->amount, 'capture_' . strtolower( $status ), __( 'PayPal declined the payment. Choose another way to pay.' ) );
        }

        return PaymentResult::retryableFailure( $session->amount, 'capture_' . strtolower( '' === $status ? 'unknown' : $status ), __( 'PayPal is still processing this payment. Try again in a moment.' ) );
    }

    /**
     * Updates the saved card's `last_used_at` after a successful payment,
     * never failing the payment over it.
     *
     * @since 1.0.0
     *
     * @param  PayPalOrder  $paypalOrder  PayPal Order.
     *
     * @return void
     */
    protected function recordSavedCardUse( PayPalOrder $paypalOrder ): void
    {
        try {
            app( PayPalVault::class )->recordSavedCardUse( $paypalOrder );
        } catch ( Throwable $exception ) {
            report( $exception );
        }
    }

    /**
     * What a capture took, in engine money (the session amount when PayPal
     * left it out or sent something unreadable).
     *
     * @since 1.0.0
     *
     * @param  PayPalCapture   $capture  Capture.
     * @param  PaymentSession  $session  Session.
     *
     * @return Money
     */
    protected function capturedAmount( PayPalCapture $capture, PaymentSession $session ): Money
    {
        $amount = $capture->amount();

        if ( null === $amount ) {
            return $session->amount;
        }

        try {
            return PayPalMoney::fromPayPal( $amount );
        } catch ( InvalidArgumentException ) {
            return $session->amount;
        }
    }

    /**
     * Saves the PayPal Order, payer, funding source, card, authorization,
     * and capture details to `orders.meta.paypal`.
     *
     * @since 1.0.0
     *
     * @param  Order               $order        Engine order.
     * @param  PayPalOrder         $paypalOrder  PayPal Order.
     * @param  PayPalCapture|null  $capture      Capture, once there is one.
     *
     * @return void
     */
    protected function recordPayment( Order $order, PayPalOrder $paypalOrder, ?PayPalCapture $capture = null ): void
    {
        $source = $paypalOrder->paymentSourceType();
        $card   = null === $source ? null : ( 'card' === $source ? $paypalOrder->get( 'payment_source.card' ) : $paypalOrder->get( 'payment_source.' . $source . '.card' ) );
        $card   = is_array( $card ) ? $card : [];

        $values = [
            'order_id'        => $paypalOrder->id(),
            'intent'          => null === $paypalOrder->intent() ? null : strtolower( $paypalOrder->intent() ),
            'mode'            => $this->client->environment()->mode,
            'funding_source'  => $source,
            'liability_shift' => $paypalOrder->liabilityShift(),
            'payer'           => self::filled( [ 'email' => $paypalOrder->payerEmail(), 'id' => $paypalOrder->payerId() ] ),
            'card'            => self::filled( [
                'brand'       => isset( $card['brand'] ) ? (string) $card['brand'] : null,
                'last_digits' => isset( $card['last_digits'] ) ? (string) $card['last_digits'] : null,
            ] ),
        ];

        $authorization = $paypalOrder->latestAuthorization();

        if ( null !== $authorization && '' !== $authorization->id() ) {
            $values['authorization'] = $this->authorizationMeta( $order, $authorization );
        }

        if ( null !== $capture ) {
            // Only a capture that took (or is taking) money becomes the
            // order's capture: refunds go against it, and the lifecycle job
            // treats the authorization as settled.
            if ( in_array( $capture->status(), [ 'COMPLETED', 'PENDING', 'PARTIALLY_REFUNDED', 'REFUNDED' ], true ) ) {
                $values['capture_id'] = $capture->id();
            }

            $values['capture_status'] = $capture->status();
            $values['pending_reason'] = 'PENDING' === $capture->status() ? $capture->pendingReason() : null;

            if ( isset( $values['capture_id'] ) && null !== $capture->authorizationId() && $capture->authorizationId() === ( $values['authorization']['id'] ?? null ) ) {
                $values['authorization']['status'] = 'CAPTURED';
            }
        }

        PayPalOrderMeta::merge( $order, self::filled( $values ), $paypalOrder->id() );

        if ( null !== $paypalOrder->vault() ) {
            try {
                app( PayPalVault::class )->recordFromOrder( $order, $paypalOrder, $this->key() );
            } catch ( Throwable $exception ) {
                // The money has moved; failing to save the method must not
                // turn the capture into a failure.
                report( $exception );
            }
        }
    }

    /**
     * Saves an authorization's state to `orders.meta.paypal.authorization`.
     *
     * @since 1.0.0
     *
     * @param  Order                $order          Engine order.
     * @param  PayPalAuthorization  $authorization  Authorization.
     *
     * @return void
     */
    protected function recordAuthorization( Order $order, PayPalAuthorization $authorization ): void
    {
        if ( '' === $authorization->id() ) {
            return;
        }

        PayPalOrderMeta::merge( $order, [ 'authorization' => $this->authorizationMeta( $order, $authorization ) ], $authorization->orderId() );
    }

    /**
     * Records that the order's authorization `$authorizationId` ended in
     * `$status` (when PayPal confirmed it through an error response), so the
     * lifecycle job stops treating it as held.
     *
     * @since 1.0.0
     *
     * @param  Order   $order            Engine order.
     * @param  string  $authorizationId  Authorization id.
     * @param  string  $status           Final status.
     *
     * @return void
     */
    protected function recordAuthorizationStatus( Order $order, string $authorizationId, string $status ): void
    {
        if ( $authorizationId !== PayPalOrderMeta::get( $order, 'authorization.id' ) ) {
            return;
        }

        PayPalOrderMeta::merge( $order, [ 'authorization' => [ 'status' => $status ] ] );
    }

    /**
     * The `orders.meta.paypal.authorization` values for `$authorization`.
     *
     * `authorized_at` and `expires_at` keep the original authorization's
     * times: PayPal's 29-day validity runs from the first authorization,
     * and a reauthorization doesn't extend it.
     *
     * @since 1.0.0
     *
     * @param  Order                $order          Engine order.
     * @param  PayPalAuthorization  $authorization  Authorization.
     *
     * @return array<string, string>
     */
    protected function authorizationMeta( Order $order, PayPalAuthorization $authorization ): array
    {
        $existing  = (array) PayPalOrderMeta::get( $order, 'authorization', [] );
        $existing  = ( $existing['id'] ?? null ) === $authorization->id() ? $existing : [];
        $createdAt = $authorization->createdAt();
        $expiresAt = $authorization->expiresAt() ?? $createdAt?->addDays( self::AUTHORIZATION_VALIDITY_DAYS );

        return self::filled( [
            'id'            => $authorization->id(),
            'status'        => $authorization->status(),
            'created_at'    => $createdAt?->toIso8601String(),
            'authorized_at' => isset( $existing['authorized_at'] ) ? (string) $existing['authorized_at'] : $createdAt?->toIso8601String(),
            'expires_at'    => isset( $existing['expires_at'] ) ? (string) $existing['expires_at'] : $expiresAt?->toIso8601String(),
        ] );
    }

    /**
     * The id of the order's authorization, or null when nothing was
     * authorized (or the Order is gone).
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Engine order.
     *
     * @throws PayPalApiException When PayPal can't be reached.
     * @throws RuntimeException   When the Order was captured without an authorization.
     *
     * @return string|null
     */
    protected function authorizationIdFor( Order $order ): ?string
    {
        $reference = (string) ( $order->getAttribute( 'payment_reference' ) ?? '' );
        $stored    = PayPalOrderMeta::describes( $order, $reference ) ? PayPalOrderMeta::get( $order, 'authorization.id' ) : null;

        if ( is_string( $stored ) && '' !== $stored ) {
            return $stored;
        }

        if ( '' === $reference ) {
            return null;
        }

        try {
            $paypalOrder = $this->client->orders()->get( $reference );
        } catch ( PayPalApiException $exception ) {
            if ( 404 === $exception->status ) {
                return null;
            }

            throw $exception;
        }

        $authorization = $paypalOrder->latestAuthorization();

        if ( null !== $authorization && '' !== $authorization->id() ) {
            return $authorization->id();
        }

        if ( in_array( $paypalOrder->latestCapture()?->status(), [ 'COMPLETED', 'PENDING', 'PARTIALLY_REFUNDED' ], true ) ) {
            throw new RuntimeException( __( 'PayPal order :id was already captured; refund it instead of voiding it.', [ 'id' => $reference ] ) );
        }

        return null;
    }

    /**
     * The capture to refund, or the failed result when there is none.
     *
     * @since 1.0.0
     *
     * @param  Order  $order   Engine order.
     * @param  Money  $amount  Amount being refunded.
     *
     * @return RefundResult|string
     */
    protected function captureIdFor( Order $order, Money $amount ): string|RefundResult
    {
        $meta      = (array) ( $order->getAttribute( 'meta' ) ?? [] );
        $reference = (string) ( $order->getAttribute( 'payment_reference' ) ?? '' );
        $current   = PayPalOrderMeta::describes( $order, $reference );
        $stored    = ( $current ? PayPalOrderMeta::get( $order, 'capture_id' ) : null ) ?? data_get( $meta, 'payment.capture_reference' );

        if ( is_string( $stored ) && '' !== $stored ) {
            return $stored;
        }

        if ( $current && null !== PayPalOrderMeta::get( $order, 'authorization.id' ) ) {
            return $this->notCaptured( $amount );
        }

        if ( '' === $reference ) {
            return RefundResult::failure( $amount, 'missing_payment_reference', __( 'This order has no PayPal payment to refund.' ) );
        }

        try {
            $paypalOrder = $this->client->orders()->get( $reference );
        } catch ( PayPalApiException $exception ) {
            return PayPalDeclines::refund( $exception, $amount );
        }

        $capture = $paypalOrder->latestCapture();

        if ( null !== $capture && '' !== $capture->id() ) {
            return $capture->id();
        }

        return null !== $paypalOrder->latestAuthorization()
            ? $this->notCaptured( $amount )
            : RefundResult::failure( $amount, 'missing_capture', __( 'This order has no PayPal payment to refund.' ) );
    }

    /**
     * The refusal for refunding a payment that was only authorized.
     *
     * @since 1.0.0
     *
     * @param  Money  $amount  Amount being refunded.
     *
     * @return RefundResult
     */
    protected function notCaptured( Money $amount ): RefundResult
    {
        return RefundResult::failure(
            $amount,
            'not_captured',
            __( 'This PayPal payment was only authorized, not captured. Void the authorization instead of refunding it.' ),
        );
    }

    /**
     * Fetches an Order again, or null when that fails.
     *
     * @since 1.0.0
     *
     * @param  string  $orderId  PayPal Order id.
     *
     * @return PayPalOrder|null
     */
    protected function refetch( string $orderId ): ?PayPalOrder
    {
        try {
            return $this->client->orders()->get( $orderId );
        } catch ( PayPalApiException ) {
            return null;
        }
    }

    /**
     * Throws when `$amount` isn't in the order's currency.
     *
     * @since 1.0.0
     *
     * @param  Order  $order   Engine order.
     * @param  Money  $amount  Amount.
     *
     * @throws PaymentCurrencyMismatchException On a mismatch.
     *
     * @return void
     */
    protected function assertCurrency( Order $order, Money $amount ): void
    {
        $expected = strtoupper( (string) $order->getAttribute( 'currency' ) );

        if ( $amount->getCurrency()->getCode() !== $expected ) {
            throw new PaymentCurrencyMismatchException( $expected, $amount->getCurrency()->getCode() );
        }
    }

    /**
     * `$values` without nulls, empty strings, and empty arrays.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $values  Values.
     *
     * @return array<string, mixed>
     */
    protected static function filled( array $values ): array
    {
        return array_filter( $values, static function ( mixed $value): bool {
            return null !== $value && '' !== $value && [] !== $value;
        } );
    }
}
