---
title: Availability
---

# Availability

Which PayPal methods a shopper sees is decided in three layers:

1. **Registration.** The four gateways are registered with the engine only while PayPal is enabled and both credentials are set. They stay registered while a method is switched off, so its past orders can still be refunded and reconciled.
2. **Per cart, on the server.** The package filters `ap.ecommerce.payment.availableGateways` at priority 10. A PayPal gateway is offered only when its `methods.*.enabled` is on, PayPal accepts the cart's currency for it, and the cart total is above zero and whole in PayPal's units (a HUF or TWD total with a fraction isn't; see [HUF and TWD](Installation-Configuration#huf-and-twd)).
3. **Per buyer, in the browser.** PayPal's SDK decides device and buyer eligibility: Apple Pay on Apple devices, Google Pay readiness, Venmo and Pay Later by the buyer's country. A driver hides an ineligible method and tells the checkout.

You can narrow the first two layers from your own code.

## Hide a method for a cart

Filter `ap.ecommerce.payment.availableGateways` at a priority above 10, so you see the package's result. The engine only accepts narrowing or reordering.

```php
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;

// No PayPal method for orders over $10,000.
addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways, Cart $cart ): array {
    if ( (int) $cart->total_amount <= 1_000_000 ) {
        return $gateways;
    }

    return array_filter( $gateways, fn ( $gateway ): bool => ! $gateway instanceof PayPalGateway );
}, 20, 2 );
```

Target one method by its key or class:

```php
use ArtisanPackUI\Ecommerce\Models\Cart;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalCardGateway;

// Cards only for signed-in customers.
addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways, Cart $cart ): array {
    if ( null === $cart->customer_id ) {
        unset( $gateways[ PayPalCardGateway::KEY ] ); // 'paypal-card'
    }

    return $gateways;
}, 20, 2 );
```

| Gateway class | Key |
| --- | --- |
| `PayPalWalletGateway` | `paypal` |
| `PayPalCardGateway` | `paypal-card` |
| `PayPalApplePayGateway` | `paypal-apple-pay` |
| `PayPalGooglePayGateway` | `paypal-google-pay` |

All four extend `ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway`, in the same namespace.

## Change the currencies

`ap.ecommerce.paypal.supportedCurrencies` filters the currency list for a method: `paypal`, `card`, `apple_pay`, `google_pay`, `venmo`, or `pay_later`. It is read when checkout decides availability, when Pay Later messages decide whether to render, and by `ecommerce-paypal:verify-credentials`.

```php
// Only offer Google Pay in euros.
addFilter( 'ap.ecommerce.paypal.supportedCurrencies', function ( array $currencies, string $method ): array {
    return 'google_pay' === $method ? [ 'EUR' ] : $currencies;
}, 10, 2 );
```

Widening a list only helps when PayPal really takes that currency for the method: PayPal rejects the Order otherwise.

## Change Pay Later and Venmo per cart

Venmo and Pay Later live inside the PayPal buttons, so they aren't gateways. Turn them off for one cart through the SDK's funding options:

```php
use ArtisanPackUI\Ecommerce\Models\Cart;

// No Pay Later on carts under $50.
addFilter( 'ap.ecommerce.paypal.clientOptions', function ( array $options, Cart $cart ): array {
    if ( (int) $cart->total_amount < 5_000 ) {
        $options['enable_funding']  = array_values( array_diff( $options['enable_funding'], [ 'paylater' ] ) );
        $options['disable_funding'] = array_values( array_unique( [ ...$options['disable_funding'], 'paylater' ] ) );
    }

    return $options;
}, 10, 2 );
```

Every PayPal gateway's options carry `enable_funding` and `disable_funding`, because all four load the same SDK. Pay Later messages are gated separately, by `pay_later_messaging.*` and `methods.paypal.pay_later`.

## Debug why a method is missing

- `php artisan ecommerce-paypal:verify-credentials` shows the enabled methods and whether each takes the store's base currency.
- Check the cart's currency against the [supported currencies](Installation-Configuration#supported-currencies).
- In the browser, an ineligible method announces why and the driver reports it to the checkout. Apple Pay needs Safari on an Apple device with a card in Wallet and a registered domain.
