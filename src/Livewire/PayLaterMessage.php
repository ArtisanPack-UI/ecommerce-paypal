<?php

/**
 * The live Pay Later message.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Livewire;

use ArtisanPackUI\EcommercePayPal\Support\PayLaterMessaging;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCheckoutModule;
use ArtisanPackUI\EcommercePayPal\Support\PayPalMoney;
use ArtisanPackUI\EcommerceStorefrontLivewire\Support\StorefrontCart;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Money\Money;

/**
 * `<livewire:artisanpack-ecommerce-paypal-pay-later-message placement="cart" />`
 * (spec §9.7): the Pay Later message for an amount that changes on the
 * page.
 *
 * With `amount` (an integer in minor units, plus `currency`; use the Blade
 * component for a `Money`), it shows that amount. Without
 * one, it shows the shopper's cart total from storefront-livewire, and
 * follows it whenever the storefront dispatches `ecommerce-cart-updated`.
 * The SDK message is mounted once (in `wire:ignore`) and updated in place.
 * The same gates as the Blade component apply ({@see PayLaterMessaging}).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayLaterMessage extends Component
{
    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-pay-later-message';

    /**
     * `product`, `cart`, or `payment`.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $placement = 'cart';

    /**
     * A fixed amount in minor units, or null for the cart total.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $amount = null;

    /**
     * Currency for a fixed amount (the store's base currency when null).
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    #[Locked]
    public ?string $currency = null;

    /**
     * The amount shown, as PayPal writes it (`240.00`); empty when no
     * message should show.
     *
     * @since 1.0.0
     *
     * @var string
     */
    #[Locked]
    public string $value = '';

    /**
     * Works out the first amount.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function mount(): void
    {
        $this->refreshAmount();
    }

    /**
     * Follows the cart total when the storefront's cart changes.
     *
     * @since 1.0.0
     *
     * @return void
     */
    #[On( 'ecommerce-cart-updated' )]
    public function refreshAmount(): void
    {
        $money       = $this->money();
        $this->value = PayLaterMessaging::enabledFor( $this->placement, $money ) && null !== $money ? PayPalMoney::toPayPal( $money )['value'] : '';
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
        $money = $this->money();

        // The box is rendered whenever messages may show here at all, so the
        // script that mounted it keeps a live node when the total drops to
        // zero and comes back; an empty value only hides it.
        return view( 'ecommerce-paypal::livewire.pay-later-message', [
            'module'   => PayPalCheckoutModule::url(),
            'settings' => null !== $money && PayLaterMessaging::placementEnabled( $this->placement, $money->getCurrency()->getCode() )
                ? PayLaterMessaging::settings( $this->placement, $money )
                : null,
        ] );
    }

    /**
     * The amount to show: the fixed one, or the shopper's cart total.
     *
     * @since 1.0.0
     *
     * @return Money|null
     */
    protected function money(): ?Money
    {
        if ( null !== $this->amount ) {
            return PayLaterMessaging::money( $this->amount, $this->currency );
        }

        if ( ! class_exists( StorefrontCart::class ) ) {
            return null;
        }

        $cart = app( StorefrontCart::class )->current();

        if ( null === $cart ) {
            return null;
        }

        return PayLaterMessaging::money( (int) ( $cart->total_amount ?? 0 ), (string) ( $cart->currency ?: '' ) ?: null );
    }
}
