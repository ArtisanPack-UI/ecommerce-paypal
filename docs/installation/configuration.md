---
title: Configuration
---

# Configuration

`php artisan ecommerce-paypal:install` publishes the config to `config/artisanpack/ecommerce-paypal.php`. You can also publish it with `php artisan vendor:publish --tag=ecommerce-paypal-config`. It loads under the runtime key `artisanpack.ecommerce-paypal`:

```php
config( 'artisanpack.ecommerce-paypal.methods.card.enabled' ); // false
```

Every key has a default, so you only need the keys you change. Most keys are also [store settings](#store-settings) the admin can change without touching config; a value saved there overrides the config value.

## Core

| Key | Env | Default | What it controls |
| --- | --- | --- | --- |
| `enabled` | `PAYPAL_ENABLED` | `false` | Register the PayPal gateways. They register only when this is `true` and both `client_id` and `client_secret` are set. |
| `mode` | `PAYPAL_MODE` | `'sandbox'` | `sandbox` or `live`. Picks the API host (`api-m.sandbox.paypal.com` or `api-m.paypal.com`), the web host, the webhook certificate host, and the dashboard links. An unknown value falls back to `sandbox`. |
| `client_id` | `PAYPAL_CLIENT_ID` | `null` | The REST app's client id. Public: the JS SDK loads with it. |
| `client_secret` | `PAYPAL_CLIENT_SECRET` | `null` | The REST app's secret. Never leaves the server. |
| `webhook_id` | `PAYPAL_WEBHOOK_ID` | `null` | The id `ecommerce-paypal:webhooks:register` prints. Comma-separate several ids during a rotation; a delivery signed for any of them is accepted. |
| `webhook_verification` | `PAYPAL_WEBHOOK_VERIFICATION` | `'local'` | `local` checks the signature against PayPal's certificate with no API call. `postback` asks PayPal's `verify-webhook-signature` endpoint instead. Any other value means `local`. See [Webhooks](Webhooks). |
| `intent` | `PAYPAL_INTENT` | `'capture'` | `capture` or `authorize`. See [Intent](#intent-capture-or-authorize). |
| `brand_name` | `PAYPAL_BRAND_NAME` | `null` | The name on PayPal's pages and the Apple Pay sheet. Empty falls back to the engine's store name (`artisanpack.ecommerce.notifications.store_name`), then `app.name`. |
| `soft_descriptor` | `PAYPAL_SOFT_DESCRIPTOR` | `null` | Text on the buyer's card statement, 22 characters at most. Empty sends none. |

## Payment methods

Each method is its own checkout choice and its own gateway. See [Payment Methods](Payment-Methods).

| Key | Env | Default | What it controls |
| --- | --- | --- | --- |
| `methods.paypal.enabled` | | `true` | Offer the PayPal buttons (gateway `paypal`). |
| `methods.paypal.pay_later` | | `true` | Offer Pay Later in the buttons where the buyer is eligible. Also required for [Pay Later messages](Payment-Methods-Pay-Later-Messaging). |
| `methods.paypal.venmo` | | `true` | Offer Venmo in the buttons where the buyer is eligible (US, USD). |
| `methods.paypal.button` | | `[ 'layout' => 'vertical', 'color' => 'gold', 'shape' => 'rect', 'label' => 'paypal' ]` | The buttons' style, passed to PayPal's `Buttons( { style } )`. Only `layout`, `color`, `shape`, `label`, `height`, and `tagline` are passed. |
| `methods.card.enabled` | | `false` | Offer card fields hosted by PayPal (gateway `paypal-card`). While it is on, the PayPal buttons hide their own card button. |
| `methods.card.three_d_secure` | | `'SCA_WHEN_REQUIRED'` | `SCA_WHEN_REQUIRED` runs 3-D Secure when the issuer or regulation asks for it; `SCA_ALWAYS` runs it on every card payment. |
| `methods.card.require_liability_shift` | | `true` | Refuse card, Apple Pay, and Google Pay payments whose 3-D Secure result is `NO` or `UNKNOWN`. See [Card Fields](Payment-Methods-Card-Fields). |
| `methods.apple_pay.enabled` | | `false` | Offer Apple Pay (gateway `paypal-apple-pay`). |
| `methods.apple_pay.domain_association` | `PAYPAL_APPLE_PAY_DOMAIN_ASSOCIATION` | `null` | Apple's domain association file: its contents, an absolute path to it, or an HTTPS URL. Empty uses `storage/app/ecommerce-paypal/apple-developer-merchantid-domain-association`. See [Apple Pay](Payment-Methods-Apple-Pay). |
| `methods.apple_pay.serve_domain_association` | `PAYPAL_APPLE_PAY_SERVE_DOMAIN_ASSOCIATION` | `true` | Register the `/.well-known/apple-developer-merchantid-domain-association` route. Turn it off when your web server serves the file. |
| `methods.google_pay.enabled` | | `false` | Offer Google Pay (gateway `paypal-google-pay`). |

## Pay Later messaging

Where PayPal's Pay Later messages may render. They also need the `paypal` method and its `pay_later` option on. See [Pay Later Messaging](Payment-Methods-Pay-Later-Messaging).

| Key | Default | Placement |
| --- | --- | --- |
| `pay_later_messaging.checkout` | `true` | `payment` (and `checkout`) |
| `pay_later_messaging.cart` | `false` | `cart` |
| `pay_later_messaging.product` | `false` | `product` |

## Vault

| Key | Default | What it controls |
| --- | --- | --- |
| `vault.enabled` | `false` | Let signed-in shoppers save PayPal and cards, and show the saved payment methods account page. Needs the `paypal` or `card` method on. See [Saved Payment Methods](Vaulting). |

## HTTP

| Key | Default | What it controls |
| --- | --- | --- |
| `http.timeout` | `15` | Request timeout in seconds. |
| `http.connect_timeout` | `5` | Connection timeout in seconds. |
| `http.retries` | `2` | How many times a request that timed out, or got a `429` or `5xx`, is retried. Retries back off exponentially (from 250 ms, capped at 8 s, honoring `Retry-After`) and reuse the same `PayPal-Request-Id`, so a retry never moves money twice. Other `4xx` responses are never retried. TLS verification is always on. |

## Logging

| Key | Env | Default | What it controls |
| --- | --- | --- | --- |
| `logging.channel` | `PAYPAL_LOG_CHANNEL` | `null` | The log channel. `null` uses the default channel. |
| `logging.debug` | `PAYPAL_LOG_DEBUG` | `false` | Also log every request and response body, redacted. Failed calls are always logged with PayPal's debug id. See [Troubleshooting](Advanced-Troubleshooting). |

## Intent: capture or authorize

| | `capture` (default) | `authorize` |
| --- | --- | --- |
| At finalize | The approved PayPal Order is captured (`POST /v2/checkout/orders/{id}/capture`). | The approved Order is authorized (`/authorize`), then the authorization is captured with `final_capture: true`. |
| Stored on the order | The capture id | The authorization id and status, then the capture id |
| `voidPendingPayment()` | A no-op: nothing is authorized | Voids an open authorization; throws if it was already captured (refund it instead) |
| The SDK | Loads with `intent=capture` | Loads with `intent=authorize` |

The engine's fraud check runs on the approved session before `capturePayment()` is called, in both intents, so a blocked payment is never captured; an approved Order that is never captured expires at PayPal on its own. In this release, `authorize` still captures in the same finalize step once the authorization succeeds. An authorization stays open only when its capture doesn't complete then: PayPal returned it as `PENDING`, or the capture failed with a retryable error and the order was left `pending` for a retry.

**Honor period.** PayPal guarantees an authorization's funds for 3 days and keeps it valid for 29. For authorizations left open, `ecommerce-paypal:authorizations:reauthorize` runs daily (scheduled by the package, `withoutOverlapping()`):

- once the 3-day honor period is over, it reauthorizes the authorization once (PayPal allows one reauthorization) while it is still valid, and adds an order note;
- 2 days before the authorization expires, it fires `ap.ecommerce.paypal.authorizationExpiring` `(Order $order, CarbonImmutable $expiresAt)` once, so you can capture it or contact the shopper.

Progress is kept on `orders.meta.paypal.authorization` (`reauthorized_at`, `expiry_notified_at`), so running the command twice on the same day changes nothing. It exits non-zero when a reauthorization fails, and does nothing while PayPal is disabled. Make sure the Laravel scheduler runs.

## Store settings

The package adds a **PayPal** group to the engine's settings registry (shown on the admin-livewire settings screen). Each setting overlays its config key, so a saved value wins over the config file and the environment.

| Setting key | Config key | Type |
| --- | --- | --- |
| `paypal.enabled` | `enabled` | boolean |
| `paypal.mode` | `mode` | select: `sandbox`, `live` |
| `paypal.client_id` | `client_id` | string (max 255) |
| `paypal.intent` | `intent` | select: `capture`, `authorize` |
| `paypal.brand_name` | `brand_name` | string (max 127) |
| `paypal.soft_descriptor` | `soft_descriptor` | string (max 22) |
| `paypal.methods.paypal.enabled` | `methods.paypal.enabled` | boolean |
| `paypal.methods.paypal.pay_later` | `methods.paypal.pay_later` | boolean |
| `paypal.methods.paypal.venmo` | `methods.paypal.venmo` | boolean |
| `paypal.methods.card.enabled` | `methods.card.enabled` | boolean |
| `paypal.methods.apple_pay.enabled` | `methods.apple_pay.enabled` | boolean |
| `paypal.methods.apple_pay.domain_association` | `methods.apple_pay.domain_association` | text |
| `paypal.methods.google_pay.enabled` | `methods.google_pay.enabled` | boolean |
| `paypal.pay_later_messaging.checkout` | `pay_later_messaging.checkout` | boolean |
| `paypal.pay_later_messaging.cart` | `pay_later_messaging.cart` | boolean |
| `paypal.pay_later_messaging.product` | `pay_later_messaging.product` | boolean |
| `paypal.vault.enabled` | `vault.enabled` | boolean |

Config-only keys (no setting): `webhook_verification`, `methods.paypal.button`, `methods.card.three_d_secure`, `methods.card.require_liability_shift`, `methods.apple_pay.serve_domain_association`, `http.*`, and `logging.*`.

**Secrets.** `client_secret` (`PAYPAL_CLIENT_SECRET`) and `webhook_id` (`PAYPAL_WEBHOOK_ID`) are registered as setting secrets: the screen shows whether each is configured, and never shows, returns, or stores the value. Set them in the environment.

**Validation.** A `paypal` constraint runs on the combined settings before every save and refuses:

| Code | When |
| --- | --- |
| `paypal-not-configured` | PayPal is enabled without both a client id and `PAYPAL_CLIENT_SECRET`. |
| `paypal-live-credentials-invalid` | PayPal is enabled in `live` mode and PayPal refuses a live token for the credentials. The answer is cached for 10 minutes per credential fingerprint. When PayPal can't be reached, the save isn't blocked. |
| `paypal-vault-unavailable` | Saved payment methods are on, but neither PayPal nor cards are. |
| `paypal-apple-pay-domain-missing` | Apple Pay is on with no domain association value and no file in `storage/app/ecommerce-paypal/`. |

## Per-method enablement

Every gateway in the family is registered while PayPal is enabled, even a method that is switched off, so orders already paid with it can still be refunded and reconciled. Switched-off methods are hidden from checkout by the availability filter. A method is offered for a cart only when:

- its `methods.*.enabled` is on;
- PayPal accepts the cart's currency for that method;
- the cart total is above zero in PayPal's units.

Device and buyer eligibility (Apple Pay on Apple devices, Google Pay readiness, Venmo and Pay Later by buyer country) is decided by PayPal's SDK in the browser; an ineligible method hides itself. See [Availability](Extending-Availability).

### Supported currencies

| Method | Currencies |
| --- | --- |
| `paypal` | AUD, BRL, CAD, CNY, CZK, DKK, EUR, HKD, HUF, ILS, JPY, MYR, MXN, TWD, NZD, NOK, PHP, PLN, GBP, SGD, SEK, CHF, THB, USD |
| `card` | AUD, CAD, CHF, CZK, DKK, EUR, GBP, HKD, HUF, JPY, NOK, NZD, PLN, SEK, SGD, USD |
| `apple_pay`, `google_pay` | AUD, BRL, CAD, CHF, CZK, DKK, EUR, GBP, HKD, HUF, ILS, JPY, MXN, NOK, NZD, PHP, PLN, SEK, SGD, THB, TWD, USD |
| `venmo` (inside the buttons) | USD |
| `pay_later` (inside the buttons and messages) | AUD, EUR, GBP, USD |

HUF, JPY, and TWD are sent to PayPal without decimals. Change any list with the `ap.ecommerce.paypal.supportedCurrencies` filter.
