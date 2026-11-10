# ArtisanPack UI Ecommerce PayPal Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package
follows [Semantic Versioning](https://semver.org/).

## [Unreleased]

The first stable release (1.0.0) of the PayPal gateway for the ArtisanPack UI
ecommerce engine. It runs on engine 1.3, PHP 8.3, and Laravel 12 or 13, and is
licensed under MIT.

### Added

- **Package foundation:** service provider, config (`artisanpack.ecommerce-paypal`), migrations, publish tags (`ecommerce-paypal-config`, `-assets`, `-migrations`, `-views`, `-lang`), `SatelliteRegistry` registration, and the `PayPalUninstaller` (keeps data by default; `--purge` deletes the current mode's vault tokens at PayPal).
- **Configuration and settings:** sandbox and live modes, credentials, webhook ids (several during a rotation), webhook verification mode, intent, branding, per-method switches, Pay Later messaging placements, vaulting, HTTP timeouts and retries, and logging. A `paypal` group in the engine's settings registry overlays the config, keeps the client secret and webhook ids as secrets, and refuses settings that can't work (missing credentials, live credentials PayPal rejects, vaulting without PayPal or cards, Apple Pay without a domain file).
- **PayPal API client:** built on Laravel's HTTP client, with a cached OAuth token, `PayPal-Request-Id` on every mutating call, retries with backoff for timeouts, `429`s, and `5xx`s, typed resources for Orders, Payments, Vault, Webhooks, and Disputes, `PayPalApiException` with PayPal's debug id, and a redactor for credentials, card data, vault ids, and payer personal data.
- **Gateway family:** `paypal` (PayPal, Pay Later, Venmo), `paypal-card`, `paypal-apple-pay`, and `paypal-google-pay`, each its own checkout choice, with per-cart availability by method switch, currency allowlist, and total.
- **Payments:**
  - Order payloads with items, breakdown, shipping, experience context, and PayPal's decimal rules (HUF, JPY, TWD), reconciled to the engine's totals;
  - payment sessions with normalized statuses;
  - idempotent capture for the `capture` and `authorize` intents, decline handling, and the `INSTRUMENT_DECLINED` restart flow;
  - voids, and the authorization lifecycle: `ecommerce-paypal:authorizations:reauthorize`, scheduled daily, reauthorizes after the 3-day honor period and warns 2 days before expiry;
  - full and partial refunds with translated decline messages;
  - the 3-D Secure liability-shift policy (`methods.card.require_liability_shift`).
- **Webhooks:**
  - local signature verification against PayPal's certificate (host allowlist, freshness, certificate checks, caching) or `postback` verification;
  - event mapping for the whole gateway family through the engine's `SharesWebhookEndpoint`;
  - queued handlers for pending, completed, and denied captures, dashboard refunds and reversals, disputes (order notes, timeline entries, and hooks), and vault token changes.
- **Storefront drivers** for `ecommerce-storefront-livewire`: `paypal-buttons`, `paypal-card-fields` (PayPal-hosted fields with 3-D Secure), `paypal-apple-pay` (with the `/.well-known/apple-developer-merchantid-domain-association` route, from contents, a file, or a URL), and `paypal-google-pay`.
- **Shared JS module** `paypal-checkout.js` (prebuilt in `dist/`): `loadSdk`, `mountButtons`, `mountCardFields`, `mountApplePay`, `mountGooglePay`, and `mountMessages`, for the Livewire drivers and React and Vue storefronts.
- **Pay Later messaging:** the `<x-ecommerce-paypal::pay-later-message>` Blade component and the live `artisanpack-ecommerce-paypal-pay-later-message` Livewire component that follows the cart total.
- **Saved payment methods:**
  - `ecommerce_paypal_customers` and `ecommerce_paypal_payment_tokens` tables holding references and display data only;
  - saving PayPal and cards during checkout, returning-buyer `id_token`s, and paying with a saved card;
  - a "Saved payment methods" account page and menu entry;
  - REST endpoints under `me/paypal/payment-methods` (list, remove, make default);
  - erasure when the engine deletes a customer.
- **Admin order panel** for `ecommerce-admin-livewire`: mode, PayPal Order, capture, and authorization ids with dashboard links, payer, funding source, liability shift, authorization expiry, and disputes, behind `order.view`.
- **Setup commands:** `ecommerce-paypal:install`, `ecommerce-paypal:verify-credentials`, and `ecommerce-paypal:webhooks:register`.
- **Hooks:** `ap.ecommerce.paypal.orderPayload`, `.clientOptions`, and `.supportedCurrencies` filters; `ap.ecommerce.paypal.captured`, `.captureDenied`, `.authorizationExpiring`, `.disputeCreated`, `.disputeUpdated`, `.disputeResolved`, and `.webhookEvent` actions.
- **Return and cancel URLs:** PayPal Orders send the shopper back to the storefront's checkout return route whether they approve or cancel (`cancel_url` defaults to `return_url`), so a cancel on PayPal's full-page flow lands on the payment step with "Your payment wasn't completed" instead of the start of checkout.
- **Testing support:** `PayPalFake` with recorded fixtures for faking PayPal's API in host applications.
- **Quality:** contract verification for all four gateways (`ecommerce:verify-satellite`), a PayPal sandbox integration suite, a browser suite for the drivers, WCAG 2.2 AA work on the drivers and account page, and English, Spanish, French, and German catalogues with the SDK locale mapped from the app locale.
- **Documentation:** installation, PayPal app setup and going live, configuration and settings reference, payment methods, Apple Pay and Google Pay go-live steps, saved payment methods, webhooks, CSP, troubleshooting, hooks, the JS module, and an upgrade guide.
