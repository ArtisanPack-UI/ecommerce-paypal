---
title: Security and CSP
---

# Security and CSP

## PCI scope

Card data is entered in PayPal-hosted iframes (card fields) or in Apple's and Google's payment sheets, and goes straight to PayPal. It never enters a Livewire component, a request to your server, your logs, or your database. That design is meant to support a **PCI DSS SAQ A** assessment, but hosted fields alone don't make a store eligible: you also have to meet SAQ A's other criteria, including protecting the payment page's scripts against tampering (for example with a Content-Security-Policy and script integrity monitoring). Confirm your eligibility with your acquirer. Saved payment methods are PayPal vault references plus display data (brand, last digits, expiry, email); no card number or CVV is stored. The engine's `ecommerce:lint:pci-columns` check covers the package's migrations.

The client config sent to the browser holds only public values: the client id, the PayPal Order id, amounts, and display options. The client secret never leaves the server, and the settings screen never shows it.

## Content-Security-Policy

If your site sends a Content-Security-Policy, allow these sources (the install command prints them). They follow PayPal's [JS SDK v5 CSP guidance](https://developer.paypal.com/sdk/js/v5/best-practices/), plus the Apple Pay and Google Pay scripts the wallets load:

| Directive | Sources | Why |
| --- | --- | --- |
| `script-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com`, `https://applepay.cdn-apple.com`, `https://pay.google.com`, and your nonce (or `'unsafe-inline'`) | PayPal's JS SDK, Apple's Apple Pay SDK, Google's `pay.js` |
| `style-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com`, and your nonce (or `'unsafe-inline'`) | The styles PayPal's SDK adds |
| `connect-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com` | SDK calls to PayPal |
| `frame-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com`, `https://pay.google.com` | PayPal's buttons, card fields, 3-D Secure, and checkout windows; Google Pay |
| `child-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com` | The same frames, for browsers that read `child-src` |
| `img-src` | `https://*.paypal.com`, `https://*.paypalobjects.com`, `https://*.venmo.com`, `data:` | PayPal's logos and button images |

With a nonce, which PayPal recommends over `'unsafe-inline'`:

```text
Content-Security-Policy:
  script-src 'self' 'nonce-RANDOM' https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com https://applepay.cdn-apple.com https://pay.google.com;
  style-src 'self' 'nonce-RANDOM' https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com;
  connect-src 'self' https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com;
  frame-src 'self' https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com https://pay.google.com;
  child-src 'self' https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com;
  img-src 'self' data: https://*.paypal.com https://*.paypalobjects.com https://*.venmo.com
```

Keep your existing sources (Livewire, Vite, fonts) alongside these. The Apple Pay and Google Pay sources are only needed when those methods are on. PayPal also recommends `Cross-Origin-Opener-Policy: same-origin-allow-popups`, so its checkout window can talk to your page. PayPal may add hosts over time; if the browser console shows a CSP violation from a PayPal domain, add it and check PayPal's guidance.

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
            ->addDirective( Directive::SCRIPT, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com', 'https://applepay.cdn-apple.com', 'https://pay.google.com' ] )
            ->addDirective( Directive::STYLE, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com' ] )
            ->addDirective( Directive::CONNECT, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com' ] )
            ->addDirective( Directive::FRAME, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com', 'https://pay.google.com' ] )
            ->addDirective( Directive::CHILD, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com' ] )
            ->addDirective( Directive::IMG, [ 'https://*.paypal.com', 'https://*.paypalobjects.com', 'https://*.venmo.com', 'data:' ] )
            ->addNonceForDirective( Directive::STYLE );
    }
}
```

`Basic` already adds a nonce to `script-src`; `addNonceForDirective( Directive::STYLE )` adds it to `style-src`, which PayPal's SDK needs.

### Nonces

With a nonce-based policy:

- **PayPal's SDK script.** `paypal-checkout.js` adds the SDK `<script>` with the page's nonce as both `nonce` and PayPal's `data-csp-nonce`, which PayPal uses for the styles it adds. It takes the nonce from the `csp_nonce` client option when a React or Vue wrapper passes one. Otherwise it reuses the nonce of a script already on the page, such as Livewire's or Vite's (`Vite::useCspNonce()`). The Apple Pay and Google Pay scripts it loads get the same nonce.
- **Inline blocks.** The Livewire drivers and the live Pay Later message use Livewire's `@script` blocks, and the Pay Later Blade component prints an inline `<script type="module">`. Give Livewire your nonce the same way as on the rest of your Livewire pages.

If no script on the page carries a nonce and none is passed, the SDK script gets none, and a nonce-only `script-src` blocks it.

## Webhook security

- Every delivery is signature-verified before anything else: locally against PayPal's certificate (fetched only from PayPal's certificate host for the mode, over TLS) or by PayPal in `postback` mode. See [Webhooks](Webhooks#verification).
- Transmissions more than 5 minutes off are refused, and the engine dedupes deliveries.
- Bodies are never trusted for money movement: outcomes and side effects re-fetch the capture, refund, dispute, or token from PayPal.
- Queued jobs carry only ids, so payer data never lands in `jobs` or `failed_jobs`.

## The Apple Pay route

`/.well-known/apple-developer-merchantid-domain-association` is public. A configured path must be named `apple-developer-merchantid-domain-association`, only HTTPS URLs are fetched (without following redirects), and a file or URL is served only when it looks like Apple's hex file. This keeps a mistaken setting from publishing an arbitrary file, but it can't prove a response came from Apple: any HTTPS endpoint that answers with a long hex string would be served publicly. Only configure a URL you control and trust, such as your own storage bucket.

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
