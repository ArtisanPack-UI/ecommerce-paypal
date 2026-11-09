<?php

/**
 * Base for the PayPal storefront payment drivers.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Livewire\Payment;

use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\Ecommerce\Registries\PaymentGatewayRegistry;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\Support\ClientPaymentConfig;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCheckoutModule;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use ArtisanPackUI\EcommerceStorefrontLivewire\Livewire\Payment\PaymentDriver;
use Throwable;

/**
 * What the four PayPal drivers share (spec §9.2): reading the client
 * config, confirming an approved Order with PayPal before telling the
 * checkout, and the "save for faster checkout" choice (spec §10.2).
 *
 * The SDK markup of every driver sits in `wire:ignore` and is mounted by
 * `paypal-checkout.js`; card data and wallet tokens stay in PayPal's and
 * the wallets' own frames and never reach a Livewire property or request.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalPaymentDriver extends PaymentDriver
{
    /**
     * Whether the shopper wants the payment method saved. Only offered when
     * the gateway's config says vaulting is available to them.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $saveMethod = false;

    /**
     * Picks up the shopper's earlier "save" choice from the config.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function mount(): void
    {
        $this->saveMethod = $this->vaultAvailable() && true === ( $this->options()['vault']['save'] ?? false );
    }

    /**
     * Checks the Order the shopper approved and reports it to the checkout.
     * Called by the view's script once the SDK approved `$orderId`.
     *
     * A card or wallet payment whose 3-D Secure result doesn't shift
     * liability fails here when the store requires it, before the order is
     * placed or anything is captured (spec §9.4).
     *
     * @since 1.0.0
     *
     * @param  string|null  $orderId  The PayPal Order the SDK approved.
     *
     * @return void
     */
    public function confirm( ?string $orderId = null ): void
    {
        $messages = $this->confirmMessages();

        if ( null === $orderId || '' === $this->reference || ! hash_equals( $this->reference, $orderId ) ) {
            $this->failed( $messages['mismatch'] );

            return;
        }

        $session = $this->session();

        if ( null === $session ) {
            $this->failed( $messages['unreachable'] );

            return;
        }

        if ( $this->refusesLiabilityShift( $session ) ) {
            $this->failed( PayPalGateway::liabilityShiftMessage() );

            return;
        }

        if ( $session->isConfirmed() ) {
            $this->confirmed();

            return;
        }

        $this->failed( $session->requiresAction() ? $messages['action'] : $messages['declined'] );
    }

    /**
     * Applies the shopper's "save for faster checkout" choice.
     *
     * PayPal only vaults a method when the Order was created asking for it,
     * so the cart's Order is replaced: the choice is kept on the cart, its
     * payment reference is cleared, and the checkout creates a new session
     * the same way it made the first. The script then points the SDK at the
     * new Order (`paypal-order-changed`). Anything that fails puts the old
     * Order and choice back.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSaveMethod(): void
    {
        $save = $this->saveMethod;

        if ( ! $this->vaultAvailable() ) {
            $this->saveMethod = false;

            return;
        }

        $gateway = app( PaymentGatewayRegistry::class )->find( $this->gateway );
        $vault   = app( PayPalVault::class );
        $cart    = '' === $this->reference ? null : Cart::query()
            ->where( 'payment_reference', $this->reference )
            ->whereNull( 'completed_order_id' )
            ->first();

        if ( ! $gateway instanceof PayPalGateway || ! $gateway->supportsSavedInstruments() || null === $cart || null === $vault->shopperFor( $cart ) ) {
            $this->saveMethod = ! $save;
            $this->failed( __( 'We couldn\'t update this choice. Reload the page and try again.' ) );

            return;
        }

        if ( $vault->wantsToSave( $cart ) === $save ) {
            return;
        }

        $session = $this->rateLimited( 'ecommerce.cart.mutate', fn (): ?PaymentSession => $this->replaceOrder( $cart, $gateway, $vault, $save ) );

        if ( null === $session ) {
            $this->saveMethod = ! $save;
            $this->failed( __( 'We couldn\'t update this choice. Try again.' ) );

            return;
        }

        $config = ClientPaymentConfig::for( $gateway, $cart->refresh(), $session );

        $this->reference = $session->reference;
        $this->config    = is_array( $config ) ? $config : $this->config;

        $this->dispatch( 'paypal-order-changed', orderId: $session->reference )->self();
    }

    /**
     * Where the published `paypal-checkout.js` is served from
     * ({@see PayPalCheckoutModule::url()}).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function moduleUrl(): string
    {
        return PayPalCheckoutModule::url();
    }

    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    abstract protected function driverKey(): string;

    /**
     * The messages {@see self::confirm()} fails with: `mismatch` (not the
     * session's Order), `unreachable` (PayPal couldn't be asked), `action`
     * (PayPal needs another step), and `declined` (not approved).
     *
     * @since 1.0.0
     *
     * @return array{mismatch: string, unreachable: string, action: string, declined: string}
     */
    abstract protected function confirmMessages(): array;

    /**
     * Whether the session's 3-D Secure result must be refused.
     *
     * @since 1.0.0
     *
     * @param  PaymentSession  $session  Session.
     *
     * @return bool
     */
    protected function refusesLiabilityShift( PaymentSession $session ): bool
    {
        $source = $session->metadata['payment_source'] ?? null;
        $shift  = $session->metadata['liability_shift'] ?? null;

        return PayPalGateway::rejectsLiabilityShift( is_string( $source ) ? $source : null, is_string( $shift ) ? $shift : null );
    }

    /**
     * Swaps the cart's Order for one created with the new "save" choice.
     *
     * @since 1.0.0
     *
     * @param  Cart           $cart     Cart.
     * @param  PayPalGateway  $gateway  Gateway.
     * @param  PayPalVault    $vault    Vault.
     * @param  bool           $save     The new choice.
     *
     * @return PaymentSession|null
     */
    protected function replaceOrder( Cart $cart, PayPalGateway $gateway, PayPalVault $vault, bool $save ): ?PaymentSession
    {
        $previous = $this->reference;

        $vault->rememberChoice( $cart, $save );

        Cart::query()->whereKey( $cart->getKey() )->where( 'payment_reference', $previous )->update( [ 'payment_reference' => null ] );

        try {
            $session = app( CheckoutService::class )->createPaymentSession( $cart->refresh(), array_filter( [ 'return_url' => $this->returnUrl() ] ) );
        } catch ( Throwable $exception ) {
            report( $exception );

            $session = null;
        }

        if ( null !== $session && $session->gatewayKey === $gateway->key() ) {
            return $session;
        }

        Cart::query()->whereKey( $cart->getKey() )->whereNull( 'completed_order_id' )->update( [ 'payment_reference' => $previous ] );
        $vault->rememberChoice( $cart->refresh(), ! $save );

        return null;
    }

    /**
     * Whether the config offers "save" to this shopper.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function vaultAvailable(): bool
    {
        return true === ( $this->options()['vault']['available'] ?? false );
    }

    /**
     * The public client id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function clientId(): string
    {
        return is_string( $this->config['publishable_key'] ?? null ) ? trim( $this->config['publishable_key'] ) : '';
    }

    /**
     * The PayPal Order id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function orderId(): string
    {
        return is_string( $this->config['client_secret'] ?? null ) ? trim( $this->config['client_secret'] ) : '';
    }

    /**
     * The config's options.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return is_array( $this->config['options'] ?? null ) ? $this->config['options'] : [];
    }

    /**
     * Whether the SDK can be mounted: there is a client id and an Order.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    protected function available(): bool
    {
        return '' !== $this->clientId() && '' !== $this->orderId();
    }

    /**
     * What the view's script needs: the module URL, the client config, the
     * payment step heading to return focus to, and its messages.
     *
     * @since 1.0.0
     *
     * @param  array<string, string>  $messages  Messages.
     * @param  array<string, mixed>   $extra     Driver-specific settings.
     *
     * @return array<string, mixed>
     */
    protected function scriptSettings( array $messages, array $extra = [] ): array
    {
        return [
            'module'   => self::moduleUrl(),
            'config'   => [
                'driver'          => $this->driverKey(),
                'publishable_key' => $this->clientId(),
                'client_secret'   => $this->orderId(),
                'options'         => $this->options(),
            ],
            'heading'  => 'ec-checkout-step-payment',
            'messages' => $messages,
            ...$extra,
        ];
    }
}
