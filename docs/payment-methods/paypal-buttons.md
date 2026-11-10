---
title: PayPal Buttons
---

# PayPal Buttons

The `paypal` gateway offers PayPal's Smart Buttons: PayPal, and Pay Later and Venmo where the buyer is eligible. It is on by default once PayPal is enabled.

| | |
| --- | --- |
| Gateway key | `paypal` |
| Config | `methods.paypal.*` |
| Driver | `paypal-buttons` (Livewire component `artisanpack-ecommerce-paypal-buttons`) |
| JS | `mountButtons()` |

## Configuration

```php
'methods' => [
    'paypal' => [
        'enabled'   => true,
        'pay_later' => true,
        'venmo'     => true,
        'button'    => [
            'layout' => 'vertical', // vertical | horizontal
            'color'  => 'gold',     // gold | blue | silver | white | black
            'shape'  => 'rect',     // rect | pill | sharp
            'label'  => 'paypal',   // paypal | checkout | buynow | pay
        ],
    ],
],
```

`button` is passed to PayPal's `style` as is (only `layout`, `color`, `shape`, `label`, `height`, and `tagline`). See PayPal's [button style guide](https://developer.paypal.com/sdk/js/reference/#style) for the values it takes.

**Funding.** With `pay_later` and `venmo` on, the SDK loads with `enable-funding=venmo,paylater`; with one off, it is added to `disable-funding`. While `methods.card.enabled` is on, `card` is also disabled in the buttons, because cards have their own checkout choice. PayPal decides buyer eligibility: Venmo needs a US buyer paying in USD, and Pay Later depends on the buyer's country and the amount.

## In the checkout

- The buttons render in a labelled group ("Pay with PayPal") inside `wire:ignore`, with a "Loading PayPal…" note until the SDK loads.
- **Approve.** `onApprove` calls the driver's `confirm()`, which checks the approved Order is the session's and reports it to the checkout.
- **Cancel.** Closing PayPal keeps the shopper on the payment step, announces a polite message, and returns focus to the step heading. When PayPal runs as a full page instead of a popup (popups blocked, some mobile browsers), "Cancel and return" goes to the storefront's checkout return route, which sends the shopper back to the payment step with "Your payment wasn't completed." The Order's `cancel_url` defaults to its `return_url` for this reason.
- **Failure.** A failed payment announces the error in an alert. When PayPal's window closing left focus on the page body, focus moves to the step heading; focus on another control is left alone.
- **Ineligible.** When the SDK says PayPal can't be offered, the driver hides the buttons, announces why, and tells the checkout.
- **Save for faster checkout.** With `vault.enabled` on, a signed-in shopper sees "Save PayPal for faster checkout". Ticking it replaces the PayPal Order with one that asks PayPal to vault the account. See [Saved Payment Methods](Vaulting).
- **Returning buyers.** For a shopper with a saved PayPal account, the server mints a PayPal `id_token` and the SDK loads with `data-user-id-token`, so PayPal shows the saved wallet.

## When PayPal declines the funding source

PayPal can decline a funding source at capture (`INSTRUMENT_DECLINED`), after the buyer approved. Capture runs in a later checkout request than the approval, so PayPal's in-popup `actions.restart()` isn't available. Instead:

1. The gateway returns a retryable failure with code `instrument_declined` and records the decline on `orders.meta.paypal.instrument_declines`.
2. The checkout re-mounts the driver for the placed order. The gateway's client config now carries `options.restart: true`.
3. The driver shows "PayPal declined that payment method. Choose another one in PayPal to finish your order." and renders the buttons on the same PayPal Order.
4. The buyer picks another funding source. The next capture uses a new `PayPal-Request-Id` (`capture-{id}-{n}`), so PayPal doesn't replay the cached decline.

The "save" checkbox is hidden while restarting.

A React or Vue wrapper that keeps `onApprove` pending until finalize answers can resolve it with `{ restart: true }`; `mountButtons()` then calls PayPal's `actions.restart()`. See [JavaScript Module](Extending-Javascript-Module).

## Changing the buttons per request

Use the `ap.ecommerce.paypal.clientOptions` filter to change `button` (or any other option) for a cart:

```php
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;

addFilter( 'ap.ecommerce.paypal.clientOptions', function ( array $options, $cart, $session, $gateway ): array {
    if ( $gateway instanceof PayPalWalletGateway ) {
        $options['button'] = [ 'layout' => 'horizontal', 'color' => 'black', 'shape' => 'pill' ];
    }

    return $options;
}, 10, 4 );
```
