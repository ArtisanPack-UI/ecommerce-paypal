---
title: Pay Later Messaging
---

# Pay Later Messaging

PayPal's Pay Later messages ("Pay in 4 interest-free payments of $25.00…") can show on product pages, in the cart, and at checkout. They work without the PayPal buttons on the page and share the SDK the checkout drivers load.

## When a message renders

A message renders only when all of these hold:

- PayPal is enabled, with credentials;
- `methods.paypal.enabled` and `methods.paypal.pay_later` are on;
- the placement's `pay_later_messaging.*` key is on (`product`, `cart`, or `checkout`; the `payment` placement uses `checkout`);
- the currency is one Pay Later supports (AUD, EUR, GBP, USD, unless you change it with `ap.ecommerce.paypal.supportedCurrencies`);
- the amount is above zero.

Otherwise nothing renders. Whether the buyer is eligible is PayPal's call: it renders nothing for an ineligible buyer or amount.

```php
'pay_later_messaging' => [
    'checkout' => true,
    'cart'     => false,
    'product'  => false,
],
```

## Blade component: a fixed amount

```blade
<x-ecommerce-paypal::pay-later-message :amount="$price" placement="product" />
```

| Attribute | Type | Default | Notes |
| --- | --- | --- | --- |
| `amount` | `Money\Money`, or an integer (or digit string) in minor units | `null` (renders nothing) | The amount the message describes |
| `placement` | `product`, `cart`, `payment`, `checkout` | `product` | Which `pay_later_messaging` key gates it, and the placement passed to PayPal |
| `currency` | ISO code | The store's base currency | Only used with a minor-units amount |
| `nonce` | string | `Vite::cspNonce()` | The Content-Security-Policy nonce for the component's inline script. See [Security and CSP](Advanced-Security-Csp#nonces) |

Extra attributes (for example `class`) go on the wrapper. The wrapper reserves one line's height (`min-h-6`), so nothing moves while PayPal loads. PayPal's own accessible markup is kept.

## Livewire component: an amount that changes

```blade
<livewire:artisanpack-ecommerce-paypal-pay-later-message placement="cart" />
```

Without `amount`, it shows the shopper's storefront-livewire cart total and follows it whenever the storefront dispatches `ecommerce-cart-updated`. It stays mounted (hidden) while the total is zero, so the message comes back when items are added. Pass `:amount` (an integer in minor units) and `currency` to show a fixed amount instead; use the Blade component for a `Money`. The cart total needs storefront-livewire; the component itself only needs Livewire.

| Property | Default |
| --- | --- |
| `placement` | `cart` |
| `amount` | `null` (the cart total) |
| `currency` | The store's base currency |

## Where to put them with storefront-livewire

The package doesn't inject messages into the storefront's views. Add them where you want them:

- **Product page.** Publish or extend the product view and add, under the price (`$price` being the displayed `Money`):

  ```blade
  <x-ecommerce-paypal::pay-later-message :amount="$price" placement="product" />
  ```

- **Cart page and drawer.** Under the totals:

  ```blade
  <livewire:artisanpack-ecommerce-paypal-pay-later-message placement="cart" />
  ```

- **Checkout.** Above the payment methods (on by default through `pay_later_messaging.checkout`):

  ```blade
  <livewire:artisanpack-ecommerce-paypal-pay-later-message placement="payment" />
  ```

Turn the matching `pay_later_messaging` key on for the product and cart placements.

## In React and Vue

Call `mountMessages( container, config, { amount, placement } )` from the [JavaScript module](Extending-Javascript-Module). It returns `{ update( amount ) }` for live totals.
