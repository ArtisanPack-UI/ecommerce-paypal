<?php

/**
 * The `paypal-card-fields` storefront payment driver.
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

use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalSavedMethodException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * PayPal's advanced card fields in storefront-livewire's checkout (spec
 * §9.2, §9.4).
 *
 * The number, expiry, security code, and name fields are PayPal-hosted
 * iframes mounted by `paypal-checkout.js` inside `wire:ignore`, so card data
 * never enters this component, its properties, or a Livewire request (the
 * store stays SAQ-A). Each field has a visible label and an inline error,
 * announced as the shopper leaves it. "Pay now" submits the fields, which
 * runs 3-D Secure (`methods.card.three_d_secure`) in PayPal's modal, and is
 * disabled while that runs. {@see PayPalPaymentDriver::confirm()} then
 * refuses a liability shift of `NO` / `UNKNOWN` when
 * `require_liability_shift` is on, so the order is never placed or
 * captured. A signed-in shopper can tick "Save this card" when vaulting is
 * on (spec §10.2).
 *
 * **Saved cards (spec §10.2).** A signed-in shopper's saved cards for the
 * current mode are listed above the fields (the default first, and chosen
 * to start with), with "Use a new card". Choosing one hides the fields;
 * "Pay with this card" then replaces the cart's Order with one paying by
 * `vault_id` and reports it to the checkout. When the bank asks for 3-D
 * Secure, the shopper is sent to PayPal's verification page and comes back
 * to the checkout's return route. A card PayPal reports invalid is removed
 * and the shopper asked to choose again. Every choice is checked against
 * the shopper who owns the cart, so another customer's card can't be used.
 *
 * Registered as `paypal-card-fields` in `PaymentDriverRegistry` only when
 * storefront-livewire is installed.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalCardFields extends PayPalPaymentDriver
{
    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const DRIVER = 'paypal-card-fields';

    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-card-fields';

    /**
     * The "Use a new card" choice.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NEW_CARD = 'new';

    /**
     * The saved card the shopper chose (a local token id), or
     * {@see self::NEW_CARD}.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public string $savedCard = self::NEW_CARD;

    /**
     * Whether the cart's current Order pays with a saved card (rather than
     * the card fields).
     *
     * @since 1.0.0
     *
     * @var bool
     */
    #[Locked]
    public bool $payingWithSaved = false;

    /**
     * Picks up the "save" choice and starts on the default saved card.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function mount(): void
    {
        parent::mount();

        $cards = $this->savedCards();

        if ( [] !== $cards ) {
            $this->savedCard = $cards[0]['id'];
        }
    }

    /**
     * Switches between a saved card and the card fields. Going back to the
     * fields after a saved-card attempt points the SDK at a card-fields
     * Order again.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function updatedSavedCard(): void
    {
        $ids = array_column( $this->savedCards(), 'id' );

        if ( self::NEW_CARD !== $this->savedCard && ! in_array( $this->savedCard, $ids, true ) ) {
            $this->savedCard = self::NEW_CARD;
        }

        if ( self::NEW_CARD !== $this->savedCard || ! $this->payingWithSaved ) {
            return;
        }

        $cart    = $this->checkoutCart();
        $gateway = $this->paypalGateway();
        $session = null === $cart || null === $gateway
            ? null
            : $this->rateLimited( 'ecommerce.cart.mutate', fn (): ?PaymentSession => $this->replaceSession( $cart, $gateway ) );

        if ( null === $session ) {
            $this->failed( __( 'We couldn\'t load the card form. Reload the page and try again.' ) );

            return;
        }

        $this->payingWithSaved = false;

        $this->dispatch( 'paypal-order-changed', orderId: $session->reference )->self();
    }

    /**
     * Pays with the chosen saved card: the cart's Order is replaced by one
     * paying with its `vault_id`, then reported to the checkout, or the
     * shopper is sent to verify the card with their bank.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function payWithSavedCard(): void
    {
        $messages = $this->confirmMessages();
        $cart     = $this->checkoutCart();
        $gateway  = $this->paypalGateway();
        $card     = null === $cart || self::NEW_CARD === $this->savedCard ? null : app( PayPalVault::class )->savedCardFor( $cart, $this->savedCard );

        if ( null === $card || null === $gateway || ! $gateway->supportsSavedInstruments() ) {
            $this->savedCard = self::NEW_CARD;
            $this->failed( PayPalSavedMethodException::unavailable()->getMessage() );

            return;
        }

        try {
            // The same payment option API storefronts send; the shopper's
            // "save" choice is kept for when they switch back to a new card.
            $session = $this->rateLimited( 'ecommerce.cart.mutate', fn (): ?PaymentSession => $this->replaceSession( $cart, $gateway, [
                CheckoutService::PAYMENT_OPTIONS => [
                    PayPalGateway::SAVED_METHOD_OPTION => (int) $card->getKey(),
                    PayPalGateway::SAVE_OPTION         => app( PayPalVault::class )->wantsToSave( $cart ),
                ],
            ] ) );
        } catch ( PayPalSavedMethodException $exception ) {
            app( PayPalVault::class )->forgetRejected( $exception );

            $this->savedCard = $this->savedCards()[0]['id'] ?? self::NEW_CARD;
            $this->failed( $exception->getMessage() );

            return;
        }

        if ( null === $session ) {
            $this->failed( $messages['unreachable'] );

            return;
        }

        $this->payingWithSaved = true;

        // A vaulted card is often charged as the Order is created. Once
        // money has moved, refusing here would leave the shopper charged
        // with no order, so the 3-D Secure policy only applies before.
        $charged = in_array( $session->status, [ PaymentSession::STATUS_SUCCEEDED, PaymentSession::STATUS_PROCESSING ], true );

        if ( ! $charged && $this->refusesLiabilityShift( $session ) ) {
            $this->failed( PayPalGateway::liabilityShiftMessage() );

            return;
        }

        if ( $session->isConfirmed() ) {
            $this->confirmed();

            return;
        }

        $verify = $session->requiresAction() ? $this->safeRedirect( $session->redirectUrl ) : null;

        if ( null !== $verify ) {
            $this->redirect( $verify );

            return;
        }

        $this->failed( $session->requiresAction() ? $messages['action'] : $messages['declined'] );
    }

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        $savedCards = $this->savedCards();

        return view( 'ecommerce-paypal::livewire.payment.paypal-card-fields', [
            'available'  => $this->available(),
            'canSave'    => $this->vaultAvailable(),
            'savedCards' => $savedCards,
            'usingSaved' => [] !== $savedCards && self::NEW_CARD !== $this->savedCard,
            'fields'     => [
                'name'   => __( 'Name on card' ),
                'number' => __( 'Card number' ),
                'expiry' => __( 'Expiration date' ),
                'cvv'    => __( 'Security code' ),
            ],
            'paypal'    => $this->scriptSettings( [
                'loadFailed' => __( 'The card form couldn\'t load. Check your connection and reload the page.' ),
                'failed'     => __( 'Your card payment couldn\'t be completed. Check your details or use another payment method.' ),
                'invalid'    => __( 'Check the highlighted card details.' ),
                'ineligible' => __( 'Card payments aren\'t available for this order. Choose another payment method.' ),
                'processing' => __( 'Processing your card. Your bank may ask you to verify it.' ),
                'fields'     => [
                    'name'   => __( 'Enter the name on the card.' ),
                    'number' => __( 'Enter a valid card number.' ),
                    'expiry' => __( 'Enter a valid expiration date.' ),
                    'cvv'    => __( 'Enter a valid security code.' ),
                ],
            ] ),
        ] );
    }

    /**
     * The shopper's saved cards as radio options (default first), plus
     * "Use a new card"; empty when they have none or can't use them.
     *
     * @since 1.0.0
     *
     * @return array<int, array{id: string, name: string, hint: string|null, default: bool}>
     */
    protected function savedCards(): array
    {
        $gateway = $this->paypalGateway();
        $cart    = null === $gateway || ! $gateway->supportsSavedInstruments() ? null : $this->checkoutCart();
        $vault   = app( PayPalVault::class );
        $shopper = null === $cart ? null : $vault->shopperFor( $cart );

        if ( null === $shopper ) {
            return [];
        }

        $cards = $vault->savedCardsFor( (int) $shopper->getKey() )->map( static fn ( PayPalPaymentToken $card ): array => [
            'id'      => (string) $card->getKey(),
            'name'    => $card->label(),
            'hint'    => self::cardHint( $card ),
            'default' => (bool) $card->is_default,
        ] )->all();

        if ( [] === $cards ) {
            return [];
        }

        return [ ...$cards, [ 'id' => self::NEW_CARD, 'name' => __( 'Use a new card' ), 'hint' => null, 'default' => false ] ];
    }

    /**
     * A saved card's expiry and default status ("Expires 12/2030 · Default").
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken  $card  Card.
     *
     * @return string|null
     */
    protected static function cardHint( PayPalPaymentToken $card ): ?string
    {
        $expiry = $card->expiry();
        $parts  = array_filter( [
            null === $expiry ? null : ( $card->isExpired() ? __( 'Expired :date', [ 'date' => $expiry ] ) : __( 'Expires :date', [ 'date' => $expiry ] ) ),
            $card->is_default ? __( 'Default' ) : null,
        ] );

        return [] === $parts ? null : implode( ' · ', $parts );
    }

    /**
     * `$url` when it is an HTTPS PayPal page, or null.
     *
     * @since 1.0.0
     *
     * @param  string|null  $url  URL.
     *
     * @return string|null
     */
    protected function safeRedirect( ?string $url ): ?string
    {
        if ( null === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) || 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ) {
            return null;
        }

        $host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );

        return 'paypal.com' === $host || str_ends_with( $host, '.paypal.com' ) ? $url : null;
    }

    /**
     * The driver key.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function driverKey(): string
    {
        return self::DRIVER;
    }

    /**
     * The confirmation failure messages.
     *
     * @since 1.0.0
     *
     * @return array{mismatch: string, unreachable: string, action: string, declined: string}
     */
    protected function confirmMessages(): array
    {
        return [
            'mismatch'    => __( 'Your card payment couldn\'t be confirmed. Try again.' ),
            'unreachable' => __( 'We couldn\'t check your card payment with PayPal. Try again.' ),
            'action'      => __( 'Your bank needs you to finish verifying this card. Try again.' ),
            'declined'    => __( 'Your card wasn\'t approved. Try again or use another payment method.' ),
        ];
    }
}
