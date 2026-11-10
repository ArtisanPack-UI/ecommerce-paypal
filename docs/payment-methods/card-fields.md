---
title: Card Fields
---

# Card Fields

The `paypal-card` gateway takes credit and debit cards in PayPal's advanced card fields: number, expiry, security code, and name, each a PayPal-hosted iframe. Card data goes straight to PayPal, never through your server or a Livewire request. That is designed to support a PCI DSS SAQ A assessment, but eligibility also depends on the rest of your payment page (including protecting its scripts) and should be confirmed with your acquirer. See [Security and CSP](Advanced-Security-Csp).

| | |
| --- | --- |
| Gateway key | `paypal-card` |
| Config | `methods.card.*` |
| Driver | `paypal-card-fields` (Livewire component `artisanpack-ecommerce-paypal-card-fields`) |
| JS | `mountCardFields()` |

## Before you turn it on

Your PayPal account must be approved for **Advanced Credit and Debit Card Payments** (ACDC) in the mode you use. In the sandbox it is on for new apps; in live, check the app's features in the dashboard. Cards are off by default:

```php
'methods' => [
    'card' => [
        'enabled'                 => true,
        'three_d_secure'          => 'SCA_WHEN_REQUIRED', // or SCA_ALWAYS
        'require_liability_shift' => true,
    ],
],
```

The card currencies are narrower than PayPal's: AUD, CAD, CHF, CZK, DKK, EUR, GBP, HKD, HUF, JPY, NOK, NZD, PLN, SEK, SGD, and USD. Cards are hidden for carts in other currencies.

While cards are on, the PayPal buttons don't show their own card button.

## 3-D Secure

"Pay now" submits the fields. PayPal runs 3-D Secure in its own modal when needed:

| `three_d_secure` | Runs 3-D Secure |
| --- | --- |
| `SCA_WHEN_REQUIRED` (default) | When the issuer or regulation (for example PSD2 in the EEA) asks for it |
| `SCA_ALWAYS` | On every card payment the card supports it for |

The value is sent as `payment_source.card.attributes.verification.method`.

### Liability shift

PayPal reports the 3-D Secure result as `liability_shift`: `POSSIBLE`, `YES`, `NO`, or `UNKNOWN`. With `require_liability_shift` on (the default), a `NO` or `UNKNOWN` result is refused before the order is placed or anything is captured, and the shopper sees "Your bank couldn't verify this card. Try another card or payment method." The same policy applies to Apple Pay and Google Pay, which also report a liability shift. PayPal-wallet payments are never refused by it.

Turn it off to accept those payments anyway; the chargeback liability then stays with you. The admin [order panel](Advanced-Admin-Order-Panel) shows the result for each order.

## In the checkout

- Each field has a visible label and an inline error linked with `aria-describedby`. Errors appear as the shopper leaves a field, and clear while they correct it.
- "Pay now" stays disabled until the form loads, and while PayPal processes the payment and 3-D Secure.
- With an invalid field on submit, focus moves to the first invalid field.
- **Save this card.** With `vault.enabled` on, a signed-in shopper sees "Save this card for faster checkout". PayPal keeps the card; the store keeps only its brand, last four digits, and expiry.

## Saved cards

A signed-in shopper's saved cards for the current mode are listed above the fields as radio options (the default first, and selected to start with), with "Use a new card". Choosing a saved card hides the fields; "Pay with this card" replaces the PayPal Order with one paying by `vault_id` with `stored_credential` (`payment_initiator: CUSTOMER`, `usage: SUBSEQUENT`), and reports it to the checkout.

When the bank asks for 3-D Secure on a saved card, the shopper is sent to PayPal's verification page and returns to the checkout's return route. A card PayPal reports invalid is removed and the shopper asked to choose again. See [Saved Payment Methods](Vaulting).

## Styling

The iframes take their text color, error color, and font from your daisyUI theme (`--color-base-content`, `--color-error`, and the page font), read when the fields mount. The field containers carry the border and background, styled by your Tailwind build. A wrapper using the JS module can pass its own `style`.

## Test cards

Use PayPal's [sandbox test cards](https://developer.paypal.com/tools/sandbox/card-testing/), including the 3-D Secure ones, to try each outcome.
