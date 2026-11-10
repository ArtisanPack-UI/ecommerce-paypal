---
title: Google Pay
---

# Google Pay

The `paypal-google-pay` gateway offers Google Pay through PayPal. It renders when Google Pay's `isReadyToPay` passes on the shopper's device and browser.

| | |
| --- | --- |
| Gateway key | `paypal-google-pay` |
| Config | `methods.google_pay.*` |
| Driver | `paypal-google-pay` (Livewire component `artisanpack-ecommerce-paypal-google-pay`) |
| JS | `mountGooglePay()` |

Saved Google Pay methods are not supported.

## Turn it on

```php
'methods' => [
    'google_pay' => [
        'enabled' => true,
    ],
],
```

Google Pay needs no domain file. It must be on for your REST app (the **Google Pay** feature in the PayPal developer dashboard).

## In the checkout

- The driver loads PayPal's `googlepay` component and Google's `pay.js`, reads the merchant info and allowed payment methods from `paypal.Googlepay().config()`, and asks `isReadyToPay`. When Google Pay isn't ready (or PayPal says the merchant isn't eligible), it hides the button and tells the checkout.
- Clicking the button opens Google's payment sheet for the cart's total and currency. The approved payment data goes to PayPal with `confirmOrder`.
- **3-D Secure.** When PayPal answers `PAYER_ACTION_REQUIRED`, the driver runs `initiatePayerAction` so the bank can verify the card, then confirms. Google Pay payments carry a liability shift, so `methods.card.require_liability_shift` applies. See [Card Fields](Payment-Methods-Card-Fields#liability-shift).
- Closing the sheet keeps the shopper on the payment step and returns focus to the step heading.

The Google Pay client runs in `TEST` in sandbox mode and `PRODUCTION` in live mode.

## Testing in the sandbox

In `TEST`, Google returns test card data, so any Google account with a card works. Use PayPal's sandbox test cards for 3-D Secure scenarios.

## Going live

1. In the live dashboard, check the app's Google Pay feature is on. Your PayPal account must be eligible for Google Pay and advanced card payments.
2. Follow Google's go-live steps for web integrations: register in the [Google Pay & Wallet Console](https://pay.google.com/business/console), submit your checkout for review with screenshots of the flow, and accept the terms. PayPal's merchant id comes from `Googlepay().config()`, so you don't paste one into this package.
3. Switch the store to `live` and run `php artisan ecommerce-paypal:verify-credentials`.
4. Make a small real payment and refund it.
