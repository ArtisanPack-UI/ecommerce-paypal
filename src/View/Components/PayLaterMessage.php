<?php

/**
 * The Pay Later message Blade component.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\View\Components;

use ArtisanPackUI\EcommercePayPal\Support\PayLaterMessaging;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCheckoutModule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Money\Money;

/**
 * `<x-ecommerce-paypal::pay-later-message :amount="$price" placement="product" />`
 * (spec §9.7).
 *
 * Renders PayPal's Pay Later message for `amount` (a `Money`, or minor units
 * in `currency`, which defaults to the store's base currency) where
 * `pay_later_messaging.*` allows it, and nothing otherwise
 * ({@see PayLaterMessaging::enabledFor()}). It works without the checkout
 * buttons. PayPal's own accessible markup is kept, and the box reserves a
 * line's height so the page doesn't shift while it loads.
 *
 * Its inline module script carries a Content-Security-Policy nonce: the
 * `nonce` attribute when given, otherwise `Vite::cspNonce()` (set with
 * `Vite::useCspNonce()`), and none when neither is set.
 *
 * For a total that changes without a page load (the cart drawer), use the
 * Livewire variant, `<livewire:artisanpack-ecommerce-paypal-pay-later-message />`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayLaterMessage extends Component
{
    /**
     * The amount, once resolved.
     *
     * @since 1.0.0
     *
     * @var Money|null
     */
    public ?Money $money;

    /**
     * The CSP nonce for the inline script, or null for none.
     *
     * @since 1.0.0
     *
     * @var string|null
     */
    public ?string $nonce;

    /**
     * Creates the component.
     *
     * @since 1.0.0
     *
     * @param  int|Money|string|null  $amount     Amount (`Money`, or minor units).
     * @param  string                 $placement  `product`, `cart`, or `payment`.
     * @param  string|null            $currency   Currency for a minor-unit amount.
     * @param  string|null            $nonce      CSP nonce for the inline script; defaults to `Vite::cspNonce()`.
     */
    public function __construct(
        Money|int|string|null $amount = null,
        public string $placement = 'product',
        ?string $currency = null,
        ?string $nonce = null,
    ) {
        $this->money = PayLaterMessaging::money( $amount, $currency );
        $nonce       = $nonce ?? Vite::cspNonce();
        $this->nonce = null === $nonce || '' === $nonce ? null : $nonce;
    }

    /**
     * Whether to render at all.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function shouldRender(): bool
    {
        return PayLaterMessaging::enabledFor( $this->placement, $this->money );
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
        /** @var Money $money */
        $money = $this->money;

        return view( 'ecommerce-paypal::components.pay-later-message', [
            'id'       => 'ec-paypal-message-' . Str::lower( Str::random( 10 ) ),
            'module'   => PayPalCheckoutModule::url(),
            'settings' => PayLaterMessaging::settings( $this->placement, $money ),
        ] );
    }
}
