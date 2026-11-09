# Package Spec: `artisanpack-ui/ecommerce-paypal`

**Status:** Draft — ready to build. Issues are open in the [v1.0 milestone](https://github.com/ArtisanPack-UI/ecommerce-paypal/milestone/1).
**Owner:** Jacob Martella
**Last updated:** 2026-11-08
**Tracker:** [ArtisanPack-UI/ecommerce#55](https://github.com/ArtisanPack-UI/ecommerce/issues/55)
**Parent plan:** `12-ecommerce-package-plan.md` — §2 (payment gateways, PCI scope, testing), §3.7, §8.1–§8.5, §15 (live-mode integration in CI)
**Engine spec:** [`13-ecommerce-engine-spec.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/plans/13-ecommerce-engine-spec.md) ("engine §N"). Engine guides: [`docs/contracts.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/contracts.md), [`docs/checkout.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/checkout.md), [`docs/satellites.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/satellites.md), [`docs/satellite-lifecycle.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/satellite-lifecycle.md), [`docs/settings.md`](https://github.com/ArtisanPack-UI/ecommerce/blob/main/docs/settings.md)
**Siblings:** [`14-ecommerce-storefront-livewire-spec.md`](https://github.com/ArtisanPack-UI/ecommerce-storefront-livewire/blob/main/plans/14-ecommerce-storefront-livewire-spec.md) ("storefront §N"). The storefront's [payment drivers guide](https://github.com/ArtisanPack-UI/ecommerce-storefront-livewire/blob/main/docs/checkout/payment-drivers.md) defines the driver contract this package implements.

## 0. Purpose

This is the build spec for the PayPal gateway satellite, the second first-party gateway after the engine's built-in Stripe gateway. It covers:

- the package identity;
- the gateways it registers and how they map onto the engine's two-phase `PaymentGateway` contract;
- the PayPal API client;
- webhooks and their verification;
- the storefront payment drivers;
- vaulting (saved payment methods);
- the one engine change it needs.

§15 breaks the build into issues.

When the parent plan and this spec disagree on PayPal behavior, this spec wins. When this spec and the engine spec disagree on a contract, hook, table, or ability, the engine spec wins.

**Out of scope for v1.0:**
- Express checkout (buttons on product and cart pages, with PayPal shipping callbacks). Parked in Future Release, see §14.
- PayPal Subscriptions / recurring billing, which belongs to `ecommerce-subscriptions`.
- Seller onboarding / Partner Referrals and multi-party (marketplace) payments.
- Payouts, invoicing, and Braintree.
- React and Vue storefront components. This package ships a framework-agnostic JS module those storefronts wrap (§9.1).

## 1. What the parent plan requires

| # | Deliverable | Section |
|---|---|---|
| 1 | PayPal Checkout through the engine's `PaymentGateway` contract (plan §3.7, §8.2) | §6, §7 |
| 2 | Inbound webhooks, signature-verified (plan §3.7, engine §9.12) | §8 |
| 3 | SAQ-A: card data is collected by PayPal's SDK only and never reaches the server (plan §2.1, §8.5) | §9, §10.4 |
| 4 | PayPal sandbox integration suite in CI, mirroring the Stripe one (plan §15) | §12.3 |
| 5 | Contract-verified via `ecommerce:verify-satellite` (plan §15.2) | §12.2 |
| 6 | `ecosystem: ecommerce` issue set on this repo (tracker #55) | §15 |

## 2. Decisions

### 2.1 Locked by the parent plan and engine

- Gateways implement `Contracts\PaymentGateway` and register through `PaymentGatewayRegistry`. Browser-side steps implement `Contracts\RendersClientPayment` (engine docs/contracts.md).
- Checkout is two-phase. `createPaymentSession()` creates the PayPal Order, the shopper approves it client-side, and finalize calls `retrievePaymentSession()` then `capturePayment()` on that same Order.
- `capturePayment()` and `voidPendingPayment()` are idempotent. Declines return results and don't throw. `voidPendingPayment()` throws when the outcome is unknown.
- `refund()` forwards `$context['idempotency_key']` to the provider.
- Inbound webhooks arrive at `POST ecommerce/webhooks/{provider}` (engine §9.12). The engine handles dedupe, rate limiting (`ecommerce.webhook.inbound`), the `ap.ecommerce.webhook_received` hooks, and async reconciliation (`ReconcilePaymentSession`).
- Registers with `SatelliteRegistry` and honors the uninstall lifecycle.
- Day-1 locales are `en`, `es`, `fr`, `de`. Every user-facing string goes through `__()` / `trans_choice()`.
- New hooks use the `ap.` prefix and camelCase segments (`ap.ecommerce.paypal.orderPayload`).

### 2.2 Decided for this package (confirmed 2026-11-08)

| # | Decision | Outcome | Why |
|---|---|---|---|
| D1 | v1.0 scope | **Everything except express checkout:** PayPal buttons with Pay Later and Venmo, advanced card fields with 3-D Secure, Apple Pay, Google Pay, and vaulting for PayPal and cards. | It matches what store owners expect from a first-party PayPal integration. |
| D2 | API client | **Laravel `Http` client**, wrapped in a typed `PayPalClient`. Not `paypal/paypal-server-sdk`. | The SDK has no Webhooks API, so verification is hand-built either way. It has also gone through three major versions in about two years, and it uses its own cURL layer (`apimatic/unirest-php`), which bypasses Laravel's timeouts, retries, events, and `Http::fake()`. Request and response shapes are checked against PayPal's published OpenAPI specs, and the sandbox suite catches API drift (§12.3). |
| D3 | Gateway shape | **Four gateways sharing one client:** `paypal` (PayPal, Pay Later, Venmo), `paypal-card`, `paypal-apple-pay`, `paypal-google-pay`. Each can be enabled separately and appears as its own entry in the checkout chooser. | Shoppers look for "Credit or debit card" next to "PayPal", not inside it. Per-key availability filters (currency, country, device) work naturally. |
| D4 | Shared webhook endpoint | PayPal posts every event to one URL, `ecommerce/webhooks/paypal`. The `paypal` gateway implements the new engine contract `SharesWebhookEndpoint` ([ArtisanPack-UI/ecommerce#207](https://github.com/ArtisanPack-UI/ecommerce/issues/207)), so reconciliation also matches carts and orders placed through the three sibling keys. | Without it, the engine's reconciler only matches carts whose key equals the route's `{provider}`. |
| D5 | Storefront UI | **Ships in this package.** Livewire drivers register into storefront-livewire's `PaymentDriverRegistry` when that package is installed. A framework-agnostic ES module (`resources/js/paypal-checkout.js`) holds all SDK logic, so the Livewire drivers and future React/Vue wrappers share it. With no storefront installed, only engine-side code loads. | One place owns PayPal's client quirks. The storefronts stay gateway-agnostic. |
| D6 | Intent | Configurable `intent`: `capture` (default) or `authorize`. With `authorize`, finalize authorizes the Order and `capturePayment()` captures the authorization. Fraud-blocked orders void it (engine §8.4). | It mirrors Stripe's manual-capture option and supports stores that capture on shipment. |
| D7 | Webhook verification | **Local verification by default**, using PayPal's documented signature scheme: `transmission_id \| transmission_time \| webhook_id \| crc32(body)`, signed with SHA256withRSA, checked against the certificate at `PAYPAL-CERT-URL`. The certificate URL must be HTTPS on `api.paypal.com` / `api.sandbox.paypal.com`, and certificates are cached. Optional `postback` mode calls `/v1/notifications/verify-webhook-signature` instead. Several webhook ids are accepted, so a webhook can be rotated. | It saves a round-trip per event and works during PayPal API degradation. The host allowlist blocks certificate-URL substitution. |
| D8 | Vault storage | **Owned by this package**: the `ecommerce_paypal_payment_tokens` table holds token ids and display data only (brand, last 4, expiry, PayPal email), plus the PayPal customer id per engine customer. | The engine has no generic saved-instrument store yet. If it gains one later, this table migrates into it. |
| D9 | JS SDK | **PayPal JS SDK v5** (`/sdk/js`, with `components=buttons,card-fields,messages,applepay,googlepay`). v6 was evaluated in #14 (2026-11-09) and **not adopted for 1.0**; see §9.1. | v5 is the one where every method in D1 is generally available today. |
| D10 | Front-end build | **No package CSS build.** Driver views use Tailwind/daisyUI classes the host compiles (the install command prints the `@source` line). The JS module ships prebuilt in `dist/` and is published as a public asset. | Same as the storefront satellites. |
| D11 | Engine release | Requires `artisanpack-ui/ecommerce ^1.3` (for #207). | The addition is non-breaking and lands in engine v1.3. |

## 3. Package identity

| Item | Value |
|---|---|
| Composer name | `artisanpack-ui/ecommerce-paypal` |
| Repository | `github.com/ArtisanPack-UI/ecommerce-paypal` |
| Namespace | `ArtisanPackUI\EcommercePayPal\` |
| Service provider | `EcommercePayPalServiceProvider` |
| Config | `config/artisanpack/ecommerce-paypal.php` → `artisanpack.ecommerce-paypal.*` |
| Views / translations | `ecommerce-paypal::` namespace; JSON translations in `lang/` |
| Livewire components | `artisanpack-ecommerce-paypal-buttons`, `-card-fields`, `-apple-pay`, `-google-pay`, `-pay-later-message`, `-saved-methods` |
| Tables | `ecommerce_paypal_customers`, `ecommerce_paypal_payment_tokens` (migrations in `database/migrations/ecommerce-paypal/`) |
| Meta namespaces | `orders.meta.paypal`, `cart.meta.paypal` |
| License | MIT |

## 4. Dependencies

### 4.1 `require`

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | Pest 4 browser plugin (§12.4). Matches the storefront and kanban satellites. |
| `illuminate/support`, `illuminate/http` | `^12.0\|^13.0` | Matches the engine. |
| `artisanpack-ui/ecommerce` | `^1.3` | `PaymentGateway`, `RendersClientPayment`, `SharesWebhookEndpoint` (#207), registries. |
| `artisanpack-ui/core`, `artisanpack-ui/hooks` | `^1.0` | Ecosystem baseline; hooks. |
| `ext-openssl` | `*` | Local webhook signature verification (D7). |

### 4.2 `suggest` (detected at runtime)

| Package | Enables |
|---|---|
| `artisanpack-ui/ecommerce-storefront-livewire` | Livewire payment drivers, Pay Later messaging, account saved-methods page |
| `artisanpack-ui/ecommerce-admin-livewire` | PayPal order panel (`OrderPanelRegistry`) and the settings screen |
| `livewire/livewire` | Required by both of the above (pulled in by them) |

### 4.3 `require-dev`

Pest 4 (+ `pest-plugin-laravel`, `pest-plugin-browser`), Orchestra Testbench, `artisanpack-ui/code-style`, `artisanpack-ui/code-style-pint`, `friendsofphp/php-cs-fixer`, `dealerdirect/phpcodesniffer-composer-installer`, `laravel/pint` (only for `code-style-pint`'s fixers; **never run Pint on this package**), and `artisanpack-ui/ecommerce-storefront-livewire` (driver tests).

## 5. Configuration and settings

`config/artisanpack/ecommerce-paypal.php`:

```php
return [
    'enabled'      => env( 'PAYPAL_ENABLED', false ),
    'mode'         => env( 'PAYPAL_MODE', 'sandbox' ),       // sandbox | live
    'client_id'    => env( 'PAYPAL_CLIENT_ID' ),
    'client_secret'=> env( 'PAYPAL_CLIENT_SECRET' ),
    'webhook_id'   => env( 'PAYPAL_WEBHOOK_ID' ),             // comma-separated during rotation
    'webhook_verification' => 'local',                        // local | postback
    'intent'       => 'capture',                              // capture | authorize
    'brand_name'   => null,                                   // falls back to the store name
    'soft_descriptor' => null,
    'methods' => [
        'paypal'     => [ 'enabled' => true, 'pay_later' => true, 'venmo' => true, 'button' => [ 'layout' => 'vertical', 'color' => 'gold', 'shape' => 'rect', 'label' => 'paypal' ] ],
        'card'       => [ 'enabled' => false, 'three_d_secure' => 'SCA_WHEN_REQUIRED', 'require_liability_shift' => true ],
        'apple_pay'  => [ 'enabled' => false ],
        'google_pay' => [ 'enabled' => false ],
    ],
    'pay_later_messaging' => [ 'checkout' => true, 'cart' => false, 'product' => false ],
    'vault' => [ 'enabled' => false ],
    'http' => [ 'timeout' => 15, 'connect_timeout' => 5, 'retries' => 2 ],
    'logging' => [ 'channel' => null, 'debug' => false ],
];
```

The same values are exposed in the engine's settings UI through `SettingsRegistry` (engine docs/settings.md):

- a `paypal` group, with each setting's `configKey` pointing at the keys above;
- `client_secret` registered as a `SettingSecret` (env fallback `PAYPAL_CLIENT_SECRET`), never echoed back;
- a `constrain( 'paypal', … )` validator that rejects `mode=live` with sandbox credentials. It checks the credentials with a token request and caches the result for 10 minutes.

## 6. Gateways

### 6.1 The gateway family

| Key | Label (translated) | Driver (`clientConfig.driver`) | PayPal `payment_source` | Partial refunds | Saved instruments |
|---|---|---|---|---|---|
| `paypal` | PayPal | `paypal-buttons` | `paypal` / `venmo` / Pay Later (`paypal`) | yes | yes (vault on) |
| `paypal-card` | Credit or debit card | `paypal-card-fields` | `card` | yes | yes (vault on) |
| `paypal-apple-pay` | Apple Pay | `paypal-apple-pay` | `apple_pay` | yes | no |
| `paypal-google-pay` | Google Pay | `paypal-google-pay` | `google_pay` | yes | no |

All four extend an abstract `PayPalGateway` that shares the client, payload builder, capture, void, refund, and status mapping. Each subclass supplies its key, label, payment source, and client config. Only `paypal` serves webhooks. Its `handleWebhook()` verifies and maps every event (§8), and it implements `SharesWebhookEndpoint::webhookGatewayKeys()`, returning the enabled sibling keys (#207). A webhook posted to a sibling key's URL (`webhooks/paypal-card`) returns `WebhookResult::unverified( 'use_paypal_endpoint' )`.

### 6.2 Availability

A gateway is registered only when the package is enabled and credentials are present. Per cart, it is offered (through `ap.ecommerce.payment.availableGateways`) only when:

- its method is enabled;
- the cart currency is one PayPal supports, and supports for that method (an allowlist in `Support\PayPalCurrencies`; Venmo and Pay Later are further limited by buyer country in the SDK);
- the cart total is above zero.

Device eligibility is checked client-side: Apple Pay needs Safari / an Apple device and a registered domain, and Google Pay needs `isReadyToPay`. When the SDK reports a method as ineligible, the driver hides it and tells the checkout through `fail()`.

### 6.3 Money

`Support\PayPalMoney` converts `Money` to PayPal's `{ currency_code, value }` decimal strings. It honors PayPal's decimal rules: HUF, JPY, and TWD use no decimal places in PayPal's API. When the decimal conversion makes the item and breakdown totals differ from the order total, the difference is moved into `discount` or `handling` so PayPal's `amount.breakdown` validation passes. The engine's totals are always the source of truth.

## 7. Payment flow (Orders v2)

### 7.1 `createPaymentSession( Cart $cart, array $context )`

1. `OrderPayloadBuilder` builds the `POST /v2/checkout/orders` body:
   - `intent` (D6);
   - one `purchase_unit` with `custom_id` (cart id), `invoice_id` (`cart-{id}-{n}`, made unique per attempt), `amount.breakdown` (item_total, shipping, tax_total, discount), `items[]` (name truncated to 127, SKU, quantity, unit_amount, category `PHYSICAL_GOODS`/`DIGITAL_GOODS`), and `shipping` (name and address from the cart, with `shipping_preference: SET_PROVIDED_ADDRESS`, or `NO_SHIPPING` for digital-only carts);
   - `payment_source.{source}.experience_context` (brand name, locale, `user_action: PAY_NOW`, return and cancel URLs from `$context` or the storefront's return route);
   - vault attributes when the shopper opted in (§10).

   The body passes through `ap.ecommerce.paypal.orderPayload` `(array $payload, Cart $cart, PayPalGateway $gateway)`.
2. The request is sent with `PayPal-Request-Id` set to `$context['idempotency_key']`, or a UUID stored on `cart.meta.paypal.request_id` when no key was passed.
3. The result is a `PaymentSession` with `reference` set to the PayPal Order id, a normalized status, the amount, and `redirectUrl` set to the `payer-action` link when present.

When the cart total or currency changes, the engine creates a new session. The old Order is left to expire in PayPal (unapproved Orders expire on their own), so nothing needs cancelling.

### 7.2 `retrievePaymentSession( string $reference )`

`GET /v2/checkout/orders/{id}` is mapped to a `PaymentSession` without side effects:

| PayPal Order status | `PaymentSession` status |
|---|---|
| `CREATED`, `SAVED` | `requires_payment_method` |
| `PAYER_ACTION_REQUIRED` | `requires_action` |
| `APPROVED` | `authorized` (ready for finalize) |
| `COMPLETED` with capture `COMPLETED` | `succeeded` |
| `COMPLETED` with capture `PENDING` | `processing` |
| `COMPLETED` with an authorization `CREATED` | `authorized` |
| `VOIDED` | `canceled` |

For card and wallet sources, the 3-D Secure result (`authentication_result.liability_shift`) is put in the session's provider data. Finalize rejects `NO`/`UNKNOWN` when `require_liability_shift` is on (§9.4).

### 7.3 `capturePayment( Order $order, PaymentSession $session )`

- **Intent `capture`.** `POST /v2/checkout/orders/{id}/capture`, with `PayPal-Request-Id` set to `capture-{order id}`. If the Order is already `COMPLETED`, the existing capture is returned instead (idempotent).
- **Intent `authorize`.** First authorize the Order (`/authorize`, idempotent on `authorize-{order id}`), then `POST /v2/payments/authorizations/{auth id}/capture` with `final_capture: true`.
- The capture id, authorization id, and payer details (email, payer id, card brand / last 4) go to `orders.meta.paypal`. The capture id is the `gatewayReference` on the `PaymentResult`.
- `INSTRUMENT_DECLINED` returns a retryable failed `PaymentResult` with code `instrument_declined`. The driver then re-opens the buttons so the shopper can pick another funding source (PayPal's documented restart flow).
- `PAYER_ACTION_REQUIRED` maps to `requires_action`, other `422`s to terminal failures, and `5xx`/timeouts to retryable failures.
- A `PENDING` capture (for example an eCheck or a review hold) returns success with status `processing`. The `PAYMENT.CAPTURE.COMPLETED` or `DENIED` webhook settles it (§8).
- Throws `PaymentCurrencyMismatchException` when the session currency differs from the order currency.

### 7.4 `voidPendingPayment( Order $order )` and the authorization lifecycle

- `POST /v2/payments/authorizations/{id}/void`. If the authorization is already `VOIDED` or `EXPIRED`, or is not found, that's a no-op. If it is `CAPTURED` or `PARTIALLY_CAPTURED`, throw (it needs a refund). Network errors and unconfirmed outcomes also throw (engine contract).
- With intent `capture` and nothing authorized, it's a no-op.
- Honor period: PayPal guarantees an authorization's funds for 3 days and keeps the authorization valid for 29. A daily scheduled job, `ecommerce-paypal:authorizations:reauthorize`, reauthorizes uncaptured authorizations older than 3 days that are still inside the validity window. It writes an order timeline note, and fires `ap.ecommerce.paypal.authorizationExpiring` 2 days before expiry.

### 7.5 `refund( Order $order, Money $amount, ?string $reason, array $context )`

`POST /v2/payments/captures/{capture id}/refund` with:

- `amount`;
- `invoice_id` set to `refund-{refund_id}`;
- `note_to_payer` set to the reason (truncated to 255);
- `custom_id` set to the engine refund id;
- `PayPal-Request-Id` set to `$context['idempotency_key']`.

Declines (`CAPTURE_FULLY_REFUNDED`, `REFUND_AMOUNT_EXCEEDED`, `REFUND_TIME_LIMIT_EXCEEDED`, …) return a failed `RefundResult` with a translated message. A `PENDING` refund returns success, and the `PAYMENT.CAPTURE.REFUNDED` webhook confirms it.

## 8. Webhooks

### 8.1 Verification

`WebhookVerifier` (D7) reads the `PAYPAL-TRANSMISSION-ID`, `-TRANSMISSION-TIME`, `-TRANSMISSION-SIG`, `-CERT-URL`, and `-AUTH-ALGO` headers. It rejects the request when any of these hold:

- a header is missing;
- the algorithm isn't `SHA256withRSA`;
- the transmission time is more than 5 minutes off;
- the certificate URL fails the HTTPS / PayPal-host allowlist;
- no configured webhook id verifies the signature.

Certificates are fetched through the PayPal client (TLS-verified), checked for validity dates and a PayPal subject, and cached by URL until they expire. Each failure is a distinct `WebhookResult::unverified()` code. Bodies are never logged on failure.

### 8.2 Event mapping

| Event | `WebhookResult` outcome | Session reference |
|---|---|---|
| `CHECKOUT.ORDER.APPROVED` | — (informational; finalize runs from the browser) | order id |
| `PAYMENT.CAPTURE.COMPLETED` | `succeeded` | `supplementary_data.related_ids.order_id` |
| `PAYMENT.AUTHORIZATION.CREATED` | `succeeded` (authorized, ready to capture) | related order id |
| `PAYMENT.CAPTURE.PENDING` | — (timeline note only) | related order id |
| `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` | `failed` | related order id |
| `PAYMENT.AUTHORIZATION.VOIDED` | `failed` (if still pending) | related order id |
| `PAYMENT.CAPTURE.REFUNDED` | `refunded`, with the refund id as `refundReference` | related order id |
| `PAYMENT.CAPTURE.REVERSED` | `refunded` (reversal), plus a timeline entry | related order id |
| `CUSTOMER.DISPUTE.CREATED`, `.UPDATED`, `.RESOLVED` | — (§8.3) | — |
| `VAULT.PAYMENT-TOKEN.CREATED`, `.DELETED` | — (§10.3) | — |

The webhook is registered with exactly this event list by `ecommerce-paypal:webhooks:register`.

### 8.3 Disputes and reversals

Dispute events look up the order by capture id and do three things:

- add a system order note through `OrderNoteService` ("PayPal dispute opened: {reason}, {amount}, respond by {date}", with a link to the Resolution Center);
- record a timeline entry;
- fire `ap.ecommerce.paypal.disputeCreated` / `disputeUpdated` / `disputeResolved` `(Order $order, array $dispute)`.

Disputes don't change the order status; that's left to store policy, for example a hook or kanban automation.

## 9. Storefront drivers

### 9.1 Shared JS module

`resources/js/paypal-checkout.js` is built to `dist/paypal-checkout.js` and published to `public/vendor/ecommerce-paypal`. It:

- loads the SDK once per page with the right `components`, `currency`, `intent`, `enable-funding`, `buyer-country` (sandbox only), `data-partner-attribution-id`, and `data-user-id-token` (vault, §10);
- exposes `mountButtons`, `mountCardFields`, `mountApplePay`, `mountGooglePay`, and `mountMessages`, each taking the client config plus `onApprove` / `onError` / `onCancel` callbacks;
- never touches card data. Card fields render in PayPal-hosted iframes.

The React and Vue storefronts wrap these functions; that work is tracked in those packages.

**v6 evaluation (#14, 2026-11-09).** The v6 web SDK now ships `card-fields`, `applepay-payments`, and `googlepay-payments` components, and documents saving cards with and without a purchase. It was still not adopted for 1.0, for three reasons:

- **Google Pay vaulting.** No v6 documentation confirms it.
- **Apple Pay saved payments.** PayPal's docs say the SDK can't show them.
- **Different integration.** v6 replaces the `?client-id=` script with a server-minted client token and per-method payment sessions. That means another server endpoint and a different approval flow for every driver.

The module's public functions (`loadSdk`, `mountButtons`, `mountCardFields`, `mountApplePay`, `mountGooglePay`, `mountMessages`) don't expose SDK details beyond the client config. A later move to v6 therefore stays inside `paypal-checkout.js` and the gateways' `clientConfig()`. Re-evaluate for 1.x once v6 documents Google Pay vaulting. Sources: [v6 reference](https://developer.paypal.com/sdk/js/reference), [v4/v5 → v6 upgrade guide](https://developer.paypal.com/v4-v6), [v6 card vaulting](https://docs.paypal.ai/payments/save/sdk/cards/js-sdk-v6-vault).

**Build.** `npm run build` (Vite) writes `dist/paypal-checkout.js`, which is committed, so Composer users need no Node toolchain. CI runs the Vitest suite and fails when `dist/` doesn't match the source. `ecommerce-paypal:install` publishes it (tag `ecommerce-paypal-assets`) and prints the CSP lines below.

CSP requirements are documented for these hosts:
- `script-src`: `https://www.paypal.com`, `https://*.paypal.com`, `https://applepay.cdn-apple.com`, `https://pay.google.com`
- `frame-src`: `https://*.paypal.com`, `https://pay.google.com`
- `connect-src`: `https://*.paypal.com`
- `img-src`: `https://*.paypalobjects.com`

### 9.2 Livewire drivers

Each driver extends the storefront's `Livewire\Payment\PaymentDriver` and is registered with `PaymentDriverRegistry` only when storefront-livewire is installed. The SDK markup sits in `wire:ignore`. `confirm()` checks `confirmedByProvider()` before calling `confirmed()`. Errors go to an assertive live region.

### 9.3 PayPal buttons (`paypal-buttons`)

- Smart buttons for PayPal, Pay Later, and Venmo, with eligibility checked by the SDK. `createOrder` returns the session reference (the Order was already created server-side).
- `onApprove` calls `confirm()`, and `onCancel` keeps the shopper on the payment step with a polite message.
- An `INSTRUMENT_DECLINED` failure from finalize restarts the buttons. Finalize runs in a later checkout request, after `onApprove` has resolved, so `actions.restart()` from the original approval isn't available. Instead:
  - the gateway records the decline (`orders.meta.paypal.instrument_declines`);
  - the checkout re-mounts the driver for the placed order, and the gateway's client config then carries `options.restart: true`;
  - the driver shows "PayPal declined that payment method…" and renders the buttons on the same Order, so the buyer picks another funding source in PayPal;
  - the next capture uses a new `PayPal-Request-Id` (`capture-{id}-{n}`), so PayPal doesn't replay the cached decline.

  `paypal-checkout.js` also supports `actions.restart()` for wrappers that keep `onApprove` pending until finalize answers: they resolve it with `{ restart: true }`. *To verify in the sandbox (#28): PayPal reopens approval when `createOrder` returns an Order that is already `APPROVED`.*
- Button style comes from config. Buttons carry a translated `aria-label` wrapper, and focus returns to the step heading after a cancel.

### 9.4 Card fields (`paypal-card-fields`)

- Card number, expiry, CVV, and name fields hosted by PayPal, styled from daisyUI tokens.
- Submit runs 3-D Secure (`SCA_WHEN_REQUIRED` or `SCA_ALWAYS`) in PayPal's modal. A rejected liability shift (`NO` / `UNKNOWN` with `require_liability_shift` on) fails with a translated "Your bank couldn't verify this card" message.
- Field-level validity events become accessible inline errors.
- "Save this card" appears when vault is on and the shopper is signed in.

### 9.5 Apple Pay (`paypal-apple-pay`)

- Rendered only when `ApplePaySession` exists and `paypal.Applepay().config()` reports eligibility.
- The package serves `/.well-known/apple-developer-merchantid-domain-association` from config, a published file, or a URL, so the domain can be registered in the PayPal dashboard.
- `ecommerce-paypal:verify-credentials` checks that the file is reachable.

### 9.6 Google Pay (`paypal-google-pay`)

- Rendered when `isReadyToPay` passes, using the merchant info from `paypal.Googlepay().config()`.
- A `PAYER_ACTION_REQUIRED` response runs `initiatePayerAction` for 3-D Secure.

### 9.7 Pay Later messaging

The `<x-ecommerce-paypal::pay-later-message :amount="…" placement="product|cart|payment" />` component (also a Livewire component for live totals) renders PayPal's messages where `pay_later_messaging.*` allows it. It works without the buttons.

How it was built (#16):

- **Gates.** A message renders only when PayPal is active, the `paypal` method and its Pay Later option are on, `pay_later_messaging.{product|cart|checkout}` is on for the placement (`payment` uses `checkout`), the currency is one Pay Later takes (AUD, EUR, GBP, USD), and the amount is above zero. Otherwise nothing renders. Buyer eligibility is PayPal's call.
- **Amount.** `:amount` takes a `Money` or minor units, with `currency=""` (default: the store's base currency).
- **Layout.** The box reserves a line's height (`min-h-6`), so nothing shifts while PayPal loads. PayPal's own accessible markup is kept.
- **Shared SDK.** The message mounts through `paypal-checkout.js`'s `mountMessages()` with the same SDK settings as the checkout drivers, so a page with both loads the SDK once.
- **Live totals.** `<livewire:artisanpack-ecommerce-paypal-pay-later-message placement="cart" />` shows the shopper's storefront cart total and follows `ecommerce-cart-updated`. The box stays rendered (hidden) while the total is zero, so the message returns when items are added. To fix the amount instead, pass `:amount` as an integer in minor units with `currency`. Use the Blade component for a `Money`.

Storefront-livewire integration (host views):

- **Product page.** Publish or extend the product view and add `<x-ecommerce-paypal::pay-later-message :amount="$price" placement="product" />` under the price, where `$price` is the displayed `Money`.
- **Cart page and drawer.** Add `<livewire:artisanpack-ecommerce-paypal-pay-later-message placement="cart" />` under the totals.
- **Checkout.** Add the same Livewire component with `placement="payment"` above the payment methods. It's on by default (`pay_later_messaging.checkout`).

## 10. Vaulting

### 10.1 Storage (D8)

- `ecommerce_paypal_customers` columns: `id`, `customer_id` (FK to `ecommerce_customers`, unique), `paypal_customer_id`, `mode`, timestamps.
- `ecommerce_paypal_payment_tokens` columns: `id`, `customer_id`, `paypal_token_id` (unique per mode), `gateway_key`, `type` (`paypal`/`card`/`venmo`), `display` (JSON: brand, last 4, expiry, email), `is_default`, `mode`, `last_used_at`, timestamps, soft deletes.
- No raw PAN, CVV, or track data, ever. The engine's PCI column lint runs in CI.
- The uninstaller deletes vault tokens at PayPal (`DELETE /v3/vault/payment-tokens/{id}`) only on `--purge`, after confirmation.

### 10.2 Saving and paying

- **Save during purchase.** When the signed-in shopper ticks "Save for faster checkout", the Order payload adds `payment_source.{paypal|card}.attributes.vault` (`store_in_vault: ON_SUCCESS`, `usage_type: MERCHANT`, `customer_type: CONSUMER`), and `attributes.customer.id` when the shopper already has a PayPal customer id. On capture, the returned `vault.id` and `customer.id` are stored.
- **Returning buyers.** For a shopper with a PayPal customer id, the server mints an `id_token` (`POST /v1/oauth2/token`, `response_type=id_token`, `target_customer_id`) for `data-user-id-token`. PayPal then shows the saved PayPal wallet in the buttons.
- **Pay with a saved card.** Checkout lists saved cards above the card fields. Choosing one creates the Order with `payment_source.card.vault_id` and `stored_credential` (`payment_initiator: CUSTOMER`, `usage: SUBSEQUENT`), with no card fields.
- `supportsSavedInstruments()` returns `vault.enabled` for `paypal` and `paypal-card`.

### 10.3 Account management

- A "Saved payment methods" account page (Livewire; added to `AccountMenuRegistry` with `satellite` set) lists tokens, sets the default, and deletes (at PayPal, then locally). Every action is authorized against the owning customer.
- REST for the React and Vue storefronts:
  - `GET ecommerce/v1/me/paypal/payment-methods`
  - `DELETE ecommerce/v1/me/paypal/payment-methods/{token}`
  - `POST …/{token}/default`

  These use the engine's Sanctum stack and `ecommerce.api` rate policy.
- `VAULT.PAYMENT-TOKEN.DELETED` webhooks soft-delete the local row.

## 11. Admin and operations

- **Order panel.** When admin-livewire is installed, a side panel registered in `OrderPanelRegistry` shows:
  - mode, PayPal Order, capture, and authorization ids, each linking to the PayPal dashboard for the mode;
  - payer email and funding source;
  - 3-D Secure liability shift;
  - authorization expiry;
  - dispute status.

  It requires `order.view`.
- **Commands:**
  - `ecommerce-paypal:install` publishes config and assets and prints the Tailwind `@source` and CSP lines.
  - `ecommerce-paypal:verify-credentials` checks the token, mode, enabled methods, and the Apple Pay domain file.
  - `ecommerce-paypal:webhooks:register` creates or updates the webhook with the §8.2 event list and prints the id to put in `PAYPAL_WEBHOOK_ID`.
  - `ecommerce-paypal:authorizations:reauthorize` is the scheduled job from §7.4.
- **Logging.** Requests and responses are logged to the configured channel only when `logging.debug` is on. `Authorization` headers, `client_secret`, `id_token`, and payer PII are always redacted. The `PayPal-Debug-Id` from error responses is always logged and surfaced in exceptions for support.

## 12. Testing

1. **Unit and feature tests (Pest 4).** Every client call uses `Http::fake()` with recorded fixtures matching PayPal's OpenAPI examples, covering the happy path, every mapped error, timeouts, and idempotent retries. Target ~90% coverage (plan §15).
2. **Contract verification.** `PaymentGatewayContractTest` runs for all four gateways, `ecommerce:verify-satellite` runs on PRs, and the release workflow signs the report with the org key (engine docs/satellite-verification.md).
3. **Sandbox integration suite** (plan §15, CI job gated on secrets):
   1. Create an Order with a `card` payment source and a PayPal sandbox test card. This is test-only and runs in CI, never in package code paths. Card orders need no buyer approval, so CI can run it unattended.
   2. Capture it.
   3. Fetch the resulting events from `GET /v1/notifications/webhooks-events` and run them through the event mapper and the engine reconciler.
   4. Refund it, and check the engine's order state at each step.
   5. Repeat with intent `authorize` and a void.

   Signature verification is covered separately, by fixtures recorded from real signed sandbox deliveries (headers, body, and certificate). CI has no public URL for PayPal to post to. PayPal-wallet approval is covered by the nightly browser suite (4).
4. **Browser tests (Pest 4 browser plugin).** Buttons render and cancel cleanly, card fields validate and complete 3-D Secure with PayPal's sandbox test cards, and the account saved-methods page works. Runs nightly against the sandbox.

## 13. Accessibility and localization

- Drivers and the account page meet WCAG 2.2 AA:
  - errors in live regions;
  - focus management after a cancel or failure;
  - every control labeled;
  - no information conveyed by color alone;
  - the Pay Later message keeps PayPal's accessible markup.
- `en`, `es`, `fr`, `de` JSON catalogues. The SDK locale is derived from `app()->getLocale()` and checked against PayPal's supported locales, with a fallback to `en_US`. Translation lint runs in CI.

## 14. Future (not v1.0)

- **Express checkout.** Buttons on product and cart pages, address collection inside PayPal, server-side shipping callbacks (`order_update_callback_config`) quoting the engine's shipping rates and tax before an address exists. Filed in Future Release (#33).
- Fastlane by PayPal (accelerated guest checkout), once generally available beyond the US.
- Moving vault storage into an engine-level saved-instrument store, if one is introduced.

## 15. Issue breakdown

Milestone **v1.0** unless noted. "Depends on" means within this repo, and "Blocked by" means cross-repo.

| # | Title | Group | Depends on |
|---|---|---|---|
| 1 | Package scaffold: composer, provider, config, code style, CI, SatelliteRegistry registration | M0 Foundation | — |
| 2 | Configuration, sandbox/live modes, and the PayPal settings group | M0 | 1 |
| 3 | PayPal API client: OAuth token cache, idempotency, retries, typed requests, error mapping, redaction | M0 | 1 |
| 4 | Setup commands: install, verify-credentials, webhooks:register | M0 | 2, 3, 11 |
| 5 | Gateway family: `paypal`, `paypal-card`, `paypal-apple-pay`, `paypal-google-pay`, and availability | M1 Gateway | 2 · blocked by ecommerce#207 |
| 6 | Order payload builder: breakdown, items, decimal rules, rounding, experience context | M1 | 3 |
| 7 | Payment sessions: create and retrieve PayPal Orders with normalized status | M1 | 5, 6 |
| 8 | Capture for `capture` and `authorize` intents, idempotent, with decline handling | M1 | 7 |
| 9 | Voids and the authorization lifecycle (honor period, reauthorization, expiry) | M1 | 8 |
| 10 | Refunds: full and partial, idempotent, with decline mapping | M1 | 8 |
| 11 | Webhook signature verification (local and postback) | M1 | 3 |
| 12 | Webhook event mapping and reconciliation across the gateway family | M1 | 8, 11 · blocked by ecommerce#207 |
| 13 | Disputes and reversals: order notes, timeline, hooks | M1 | 12 |
| 14 | Client config and the shared `paypal-checkout.js` SDK module | M2 Client | 5, 7 |
| 15 | Livewire `paypal-buttons` driver (PayPal, Pay Later, Venmo) | M2 | 14 |
| 16 | Pay Later messaging component | M2 | 14 |
| 17 | Advanced card fields driver with 3-D Secure and liability-shift policy | M2 | 8, 14 |
| 18 | Apple Pay driver and domain association route | M2 | 14 |
| 19 | Google Pay driver | M2 | 14 |
| 20 | Vault storage: PayPal customers and payment tokens | M3 Vault | 1 |
| 21 | Save PayPal and cards during checkout; returning-buyer id tokens | M3 | 15, 17, 20 |
| 22 | Pay with a saved card or PayPal wallet | M3 | 21 |
| 23 | Saved payment methods in the account (Livewire + REST) and vault webhooks | M3 | 12, 20 |
| 24 | Admin order panel: PayPal transaction details | M4 Ops | 8 |
| 25 | Extension surface: hooks and filters, documented and tested | M4 | 6, 8, 13 |
| 26 | Contract verification for all four gateways + verify-satellite workflow | M4 | 10, 12 |
| 27 | PayPal sandbox integration suite in CI | M4 | 10, 12 |
| 28 | Browser test suite (Pest 4 browser plugin) for the drivers | M4 | 15, 17, 23 |
| 29 | Accessibility audit (WCAG 2.2 AA) and fixes | M4 | 15–19, 23 |
| 30 | `es`, `fr`, `de` catalogues + translation lint | M4 | 15–23 |
| 31 | Documentation: README, setup, configuration, CSP, extending | M4 | 4, 14, 25 |
| 32 | v1.0 release: changelog, verify report, engine satellite listing | M4 | 26–31 |
| 33 | Express checkout from product and cart pages | Future Release | 15, 18, 19 |
