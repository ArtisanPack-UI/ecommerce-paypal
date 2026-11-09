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

use ArtisanPackUI\Ecommerce\Contracts\PaymentGateway;
use ArtisanPackUI\Ecommerce\Contracts\RendersClientPayment;
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentResult;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\Ecommerce\ValueObjects\RefundResult;
use ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult;
use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Payloads\OrderPayloadBuilder;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCurrencies;
use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use Illuminate\Http\Request;
use LogicException;
use Money\Currency;
use Money\Money;

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
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalGateway implements PaymentGateway, RendersClientPayment
{
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

        return $this->isEnabled()
            && '' !== $currency
            && PayPalCurrencies::supports( $this->method(), $currency )
            && PayPalMoney::toUnits( new Money( (int) ( $cart->getAttribute( 'total_amount' ) ?? 0 ), new Currency( $currency ) ) ) > 0;
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
     * Creates the PayPal Order for `$cart`.
     *
     * @since 1.0.0
     *
     * @param  Cart                  $cart     Cart.
     * @param  array<string, mixed>  $context  Context.
     *
     * @throws LogicException Until payment sessions land (#7).
     *
     * @return PaymentSession
     */
    public function createPaymentSession( Cart $cart, array $context = [] ): PaymentSession
    {
        throw $this->pending( 'createPaymentSession', 7 );
    }

    /**
     * Loads a PayPal Order as a session.
     *
     * @since 1.0.0
     *
     * @param  string  $reference  PayPal Order id.
     *
     * @throws LogicException Until payment sessions land (#7).
     *
     * @return PaymentSession
     */
    public function retrievePaymentSession( string $reference ): PaymentSession
    {
        throw $this->pending( 'retrievePaymentSession', 7 );
    }

    /**
     * Captures the payment.
     *
     * @since 1.0.0
     *
     * @param  Order           $order    Order.
     * @param  PaymentSession  $session  Session.
     *
     * @throws LogicException Until captures land (#8).
     *
     * @return PaymentResult
     */
    public function capturePayment( Order $order, PaymentSession $session ): PaymentResult
    {
        throw $this->pending( 'capturePayment', 8 );
    }

    /**
     * Voids a pending authorization.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @throws LogicException Until voids land (#9).
     *
     * @return void
     */
    public function voidPendingPayment( Order $order ): void
    {
        throw $this->pending( 'voidPendingPayment', 9 );
    }

    /**
     * Refunds a capture.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order    Order.
     * @param  Money                 $amount   Amount.
     * @param  string|null           $reason   Reason.
     * @param  array<string, mixed>  $context  Context.
     *
     * @throws LogicException Until refunds land (#10).
     *
     * @return RefundResult
     */
    public function refund( Order $order, Money $amount, ?string $reason = null, array $context = [] ): RefundResult
    {
        throw $this->pending( 'refund', 10 );
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
     * Describes the browser-side payment step.
     *
     * The PayPal JS SDK needs the client id (public), the Order id, the
     * currency, and the intent. The full configuration (button style,
     * funding sources, 3-D Secure) arrives with the shared JS module (#14).
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
        return [
            'driver'          => $this->driver(),
            'flow'            => 'embedded',
            'publishable_key' => $this->client->credentials()->clientId,
            'client_secret'   => null,
            'redirect_url'    => $session->redirectUrl,
            'options'         => [
                'order_id' => $session->reference,
                'currency' => $session->amount->getCurrency()->getCode(),
                'intent'   => 'authorize' === strtolower( (string) config( 'artisanpack.ecommerce-paypal.intent', 'capture' ) ) ? 'authorize' : 'capture',
                'mode'     => $this->client->environment()->mode,
            ],
        ];
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
     * The exception for a payment operation that isn't built yet.
     *
     * @since 1.0.0
     *
     * @param  string  $operation  Method name.
     * @param  int     $issue      Issue that adds it.
     *
     * @return LogicException
     */
    protected function pending( string $operation, int $issue ): LogicException
    {
        return new LogicException( sprintf( 'PayPal %s() is not available yet (ArtisanPack-UI/ecommerce-paypal#%d).', $operation, $issue ) );
    }
}
