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

The package serves the file at `/.well-known/apple-developer-merchantid-domain-association` (route `artisanpack.ecommerce-paypal.apple-pay.domain-association`), with no session or cookies, as `text/plain` with `X-Content-Type-Options: nosniff`, cached for an hour. Tell it where the file is with `methods.apple_pay.domain_association` (`PAYPAL_APPLE_PAY_DOMAIN_ASSOCIATION`, also a store setting). It takes one of:

| Value | Example | Notes |
| --- | --- | --- |
| Empty (default) | | Uses `storage/app/ecommerce-paypal/apple-developer-merchantid-domain-association`. Put the downloaded file there. |
| The file's contents | `7B227073704964223A2246...` | Paste the hex. |
| An absolute path | `/var/www/shared/apple-developer-merchantid-domain-association` | The file must be named `apple-developer-merchantid-domain-association`, so the setting can't expose any other file. |
| An HTTPS URL | `https://cdn.example.com/apple-developer-merchantid-domain-association` | Fetched without following redirects and cached for an hour; a failed fetch is retried after a minute. `http://` URLs are refused. |

Whatever the source, pasted contents included, the route only serves contents that look like Apple's file (only hex characters and whitespace, with a run of at least 32 hex characters). Otherwise, or when nothing is configured, it returns `404`.

The settings screen refuses a value it couldn't serve (`paypal-apple-pay-domain-invalid`): contents that aren't Apple's hex, an `http://` URL, or a path that doesn't exist, can't be read, isn't named `apple-developer-merchantid-domain-association`, or doesn't hold Apple's contents. While Apple Pay is on with the value empty, the file in `storage/app/ecommerce-paypal/` must hold Apple's contents too. An HTTPS URL is only checked when the route fetches it, so check the route with the `curl` command in step 3 after setting one.

**Serving it yourself.** If your web server or CDN already serves `/.well-known/…`, set `methods.apple_pay.serve_domain_association` (`PAYPAL_APPLE_PAY_SERVE_DOMAIN_ASSOCIATION`) to `false`, and the package doesn't register the route. This key is read at boot, so it is config-only.

Apple fetches the file from the domain root. If your app is served under a path, serve the file at the root yourself and turn the route off.

## 3. Register the domain

In the PayPal dashboard, add each domain (and subdomain) that shows Apple Pay. PayPal asks Apple to verify it by fetching the file. Apple Pay is still off at this point, so check the file is reachable directly, without following redirects, as Apple does:

```bash
curl --fail --max-redirs 0 https://your-store.example/.well-known/apple-developer-merchantid-domain-association
```

It should print the hex file, not an HTML page or a redirect. Once Apple Pay is on (step 4), `php artisan ecommerce-paypal:verify-credentials` runs the same check for you: it fetches `{APP_URL origin}/.well-known/apple-developer-merchantid-domain-association` and fails when the app isn't on HTTPS, the response isn't the file, or it is an HTML page. It skips this check while Apple Pay is off.

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
- The Apple Pay sheet shows the total labeled with `brand_name` (or the app name), in the cart's currency, and asks for the billing postal address.
- Apple Pay payments carry a 3-D Secure liability shift, so `methods.card.require_liability_shift` applies to them. See [Card Fields](Payment-Methods-Card-Fields#liability-shift).
- Canceling the sheet keeps the shopper on the payment step and returns focus to the step heading.

## Testing in the sandbox

You need a Mac or iPhone signed in to an [Apple sandbox tester account](https://developer.apple.com/apple-pay/sandbox-testing/) with a test card in Wallet, and a sandbox REST app with Apple Pay on and your HTTPS domain (for example a tunnel) registered.

## Going live

1. In the live dashboard, check the app's Apple Pay feature is on. Your PayPal account must be eligible for Apple Pay and advanced card payments.
2. Register every production domain in the live app, and serve the domain association file the live dashboard gives you.
3. Run `php artisan ecommerce-paypal:verify-credentials` in production.
4. Make a small real payment from an Apple device and refund it.
