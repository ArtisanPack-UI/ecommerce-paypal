---
title: Security and CSP
---

# Security and CSP

## PCI scope

Card data is entered in PayPal-hosted iframes (card fields) or in Apple's and Google's payment sheets, and goes straight to PayPal. It never enters a Livewire component, a request to your server, your logs, or your database, so the store stays in **PCI DSS SAQ-A** scope. Saved payment methods are PayPal vault references plus display data (brand, last digits, expiry, email); no card number or CVV is stored. The engine's `ecommerce:lint:pci-columns` check covers the package's migrations.

The client config sent to the browser holds only public values: the client id, the PayPal Order id, amounts, and display options. The client secret never leaves the server, and the settings screen never shows it.

## Content-Security-Policy

If your site sends a Content-Security-Policy, allow these sources (the install command prints them):

| Directive | Sources | Why |
| --- | --- | --- |
| `script-src` | `https://www.paypal.com`, `https://*.paypal.com`, `https://applepay.cdn-apple.com`, `https://pay.google.com` | PayPal's JS SDK, Apple's Apple Pay SDK, Google's `pay.js` |
| `frame-src` | `https://*.paypal.com`, `https://pay.google.com` | PayPal's buttons, card fields, 3-D Secure, and checkout windows; Google Pay |
| `connect-src` | `https://*.paypal.com` | SDK calls to PayPal |
| `img-src` | `https://*.paypalobjects.com` | PayPal's logos and button images |

```text
Content-Security-Policy:
  script-src 'self' https://www.paypal.com https://*.paypal.com https://applepay.cdn-apple.com https://pay.google.com;
  frame-src 'self' https://*.paypal.com https://pay.google.com;
  connect-src 'self' https://*.paypal.com;
  img-src 'self' data: https://*.paypalobjects.com
```

Keep your existing sources (Livewire, Vite, fonts) alongside these. The Apple Pay and Google Pay sources are only needed when those methods are on. PayPal may add hosts over time; if the browser console shows a CSP violation from a PayPal domain, add it and check PayPal's [CSP guidance](https://developer.paypal.com/sdk/js/configuration/).

### With `spatie/laravel-csp` (v2)

```php
use Spatie\Csp\Directive;
use Spatie\Csp\Policies\Basic;

class StorePolicy extends Basic
{
    public function configure(): void
    {
        parent::configure();

        $this
            ->addDirective( Directive::SCRIPT, [ 'https://www.paypal.com', 'https://*.paypal.com', 'https://applepay.cdn-apple.com', 'https://pay.google.com' ] )
            ->addDirective( Directive::FRAME, [ 'https://*.paypal.com', 'https://pay.google.com' ] )
            ->addDirective( Directive::CONNECT, [ 'https://*.paypal.com' ] )
            ->addDirective( Directive::IMG, [ 'https://*.paypalobjects.com' ] );
    }
}
```

### Inline scripts

The Livewire drivers and the live Pay Later message use Livewire's `@script` blocks; the Pay Later Blade component prints an inline `<script type="module">`. With a nonce-based `script-src`, make sure those are covered the same way as the rest of your Livewire pages.

## Webhook security

- Every delivery is signature-verified before anything else: locally against PayPal's certificate (fetched only from PayPal's certificate host for the mode, over TLS) or by PayPal in `postback` mode. See [Webhooks](Webhooks#verification).
- Transmissions more than 5 minutes off are refused, and the engine dedupes deliveries.
- Bodies are never trusted for money movement: outcomes and side effects re-fetch the capture, refund, dispute, or token from PayPal.
- Queued jobs carry only ids, so payer data never lands in `jobs` or `failed_jobs`.

## The Apple Pay route

`/.well-known/apple-developer-merchantid-domain-association` is public. A configured path must be named `apple-developer-merchantid-domain-association`, only HTTPS URLs are fetched (without following redirects), and a file or URL is served only when it looks like Apple's hex file. This keeps a mistaken setting from publishing another file or an internal service's response.

## Logging and redaction

Failed PayPal calls are always logged (with the debug id). Request and response bodies are logged only with `logging.debug`. Before anything is logged, the redactor masks:

| Category | Keys (matched case-insensitively, anywhere in the payload) |
| --- | --- |
| Credentials and tokens | `authorization`, `client_secret`, `access_token`, `refresh_token`, `id_token`, `nonce` |
| Card data | `number`, `security_code`, `last_digits`, `expiry`, `brand`, `bin` |
| Saved methods | `vault_id`, the vault token `id` in an Order's payment source, and token ids in `/v3/vault/payment-tokens/{id}` and `setup-tokens` paths and `href` links |
| Payer personal data | `email_address`, `email`, `given_name`, `surname`, `full_name`, `address`, `address_line_1`, `address_line_2`, `admin_area_1`, `admin_area_2`, `postal_code`, `phone`, `phone_number`, `national_number`, `birth_date`, `tax_info`, `tax_id`, and `name` inside `card`, `payer`, `shipping`, `apple_pay`, `google_pay`, `venmo`, and `paypal` objects |
| Error details | `details[].value`, which can echo rejected card input |
| Headers | `Authorization`, `PayPal-Auth-Assertion`, `Cookie`, `Set-Cookie` |

Vault responses also have their token ids masked. Line item names stay readable. Even so, keep `logging.debug` off in production except while diagnosing a problem, and send PayPal logs to a channel with limited access (`logging.channel`).

## The admin order panel

The panel requires the engine's `order.view` ability on every request (a shopper owning the order isn't enough). It shows the payer's email; restrict `order.view` accordingly.
