---
title: Apple Pay
---

# Apple Pay

The `paypal-apple-pay` gateway offers Apple Pay through PayPal. It renders only in Safari (or another browser on an Apple device) where `ApplePaySession` exists and PayPal reports the merchant eligible.

| | |
| --- | --- |
| Gateway key | `paypal-apple-pay` |
| Config | `methods.apple_pay.*` |
| Driver | `paypal-apple-pay` (Livewire component `artisanpack-ecommerce-paypal-apple-pay`) |
| JS | `mountApplePay()` |

Apple Pay needs HTTPS and a domain registered with PayPal. Saved Apple Pay methods are not supported.

## 1. Get the domain association file

Apple verifies each domain that shows Apple Pay by fetching a file from it:

```text
https://your-store.example/.well-known/apple-developer-merchantid-domain-association
```

Download the file from the PayPal developer dashboard (open your app, then the **Apple Pay** feature, then **Manage** / **Add domain**). The file is a single run of hex characters.

## 2. Serve it

The package serves the file at `/.well-known/apple-developer-merchantid-domain-association` (route `artisanpack.ecommerce-paypal.apple-pay.domain-association`), with no session or cookies, as `text/plain`, cached for an hour. Tell it where the file is with `methods.apple_pay.domain_association` (`PAYPAL_APPLE_PAY_DOMAIN_ASSOCIATION`, also a store setting). It takes one of:

| Value | Example | Notes |
| --- | --- | --- |
| Empty (default) | | Uses `storage/app/ecommerce-paypal/apple-developer-merchantid-domain-association`. Put the downloaded file there. |
| The file's contents | `7B227073704964223A2246...` | Paste the hex. |
| An absolute path | `/var/www/shared/apple-developer-merchantid-domain-association` | The file must be named `apple-developer-merchantid-domain-association`, so the setting can't expose any other file. |
| An HTTPS URL | `https://cdn.example.com/apple-developer-merchantid-domain-association` | Fetched without following redirects and cached for an hour; a failed fetch is retried after a minute. `http://` URLs are refused. |

A file or URL is only served when its contents look like Apple's file (only hex characters and whitespace, with a run of at least 32 hex characters). Otherwise, or when nothing is configured, the route returns `404`.

**Serving it yourself.** If your web server or CDN already serves `/.well-known/…`, set `methods.apple_pay.serve_domain_association` (`PAYPAL_APPLE_PAY_SERVE_DOMAIN_ASSOCIATION`) to `false`, and the package doesn't register the route. This key is read at boot, so it is config-only.

Apple fetches the file from the domain root. If your app is served under a path, serve the file at the root yourself and turn the route off.

## 3. Register the domain

In the PayPal dashboard, add each domain (and subdomain) that shows Apple Pay. PayPal asks Apple to verify it by fetching the file. Check it is reachable first:

```bash
php artisan ecommerce-paypal:verify-credentials
```

With Apple Pay on, the command fetches `{APP_URL origin}/.well-known/apple-developer-merchantid-domain-association` without following redirects (as Apple does), and fails when the app isn't on HTTPS, the response isn't the file, or it is an HTML page.

## 4. Turn it on

```php
'methods' => [
    'apple_pay' => [
        'enabled' => true,
    ],
],
```

The settings screen refuses to enable Apple Pay without a domain association value or the file in `storage/app/ecommerce-paypal/` (`paypal-apple-pay-domain-missing`).

## In the checkout

- The driver checks `ApplePaySession.canMakePayments()` and PayPal's `Applepay().config()`. When either says no, it hides the button and tells the checkout.
- The Apple Pay sheet shows the total labelled with `brand_name` (or the app name), in the cart's currency, and asks for the billing postal address.
- Apple Pay payments carry a 3-D Secure liability shift, so `methods.card.require_liability_shift` applies to them. See [Card Fields](Payment-Methods-Card-Fields#liability-shift).
- Canceling the sheet keeps the shopper on the payment step and returns focus to the step heading.

## Testing in the sandbox

You need a Mac or iPhone signed in to an [Apple sandbox tester account](https://developer.apple.com/apple-pay/sandbox-testing/) with a test card in Wallet, and a sandbox REST app with Apple Pay on and your HTTPS domain (for example a tunnel) registered.

## Going live

1. In the live dashboard, check the app's Apple Pay feature is on. Your PayPal account must be eligible for Apple Pay and advanced card payments.
2. Register every production domain in the live app, and serve the domain association file the live dashboard gives you.
3. Run `php artisan ecommerce-paypal:verify-credentials` in production.
4. Make a small real payment from an Apple device and refund it.
