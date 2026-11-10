---
title: Advanced Overview
---

# Advanced

Commands, operations, security, testing, and contributor tooling.

## In this section

- [Artisan Commands](Advanced-Artisan-Commands) - `install`, `verify-credentials`, `webhooks:register`, `authorizations:reauthorize`, and `prune-vault`
- [Security and CSP](Advanced-Security-Csp) - PCI scope, Content-Security-Policy sources, and what is redacted
- [Troubleshooting](Advanced-Troubleshooting) - PayPal debug ids, logging, and common problems
- [Admin Order Panel](Advanced-Admin-Order-Panel) - The PayPal side panel on admin-livewire's order screen
- [Testing Your Application](Advanced-Testing) - Faking PayPal with `PayPalFake` in your own tests
- [Upgrade Guide](Advanced-Upgrade-Guide) - Upgrading between versions
- [Sandbox Integration Tests](Advanced-Sandbox-Tests) - The package's suite against PayPal's sandbox (contributors)
- [Browser Tests](Advanced-Browser-Tests) - The package's browser suite for the drivers (contributors)

## How the package talks to PayPal

Every call goes through one client, `ArtisanPackUI\EcommercePayPal\Api\PayPalClient`, built on Laravel's HTTP client (not PayPal's PHP SDK), so `Http::fake()` intercepts it. For each request it:

- authenticates with a cached OAuth2 token, fetching a new one once on a `401`;
- sends a `PayPal-Request-Id` on every mutating call, so a repeated request returns PayPal's original result instead of acting twice;
- retries timeouts, `429`s, and `5xx`s up to `http.retries` times with exponential backoff and jitter (honoring `Retry-After`), always with the same request id;
- applies `http.timeout` and `http.connect_timeout`, with TLS verification always on;
- throws `PayPalApiException` on failure and logs PayPal's debug id.

The client reads config on every call, so a settings change (mode, client id) applies straight away.

### Idempotency keys

| Operation | `PayPal-Request-Id` |
| --- | --- |
| Create Order | `session-{cart id}-{hash}` when the checkout sends an idempotency key: a hash of the gateway key and the checkout's key, so it is unique per cart and gateway and at most 108 characters. Without a key, a UUID kept on `cart.meta.paypal.request_id`, reused only until it creates an Order |
| Update the shipping address | `patch-{order id}-{hash of the address}` |
| Capture Order | `capture-{order id}` (`capture-{order id}-{n}` after an `INSTRUMENT_DECLINED`) |
| Authorize Order | `authorize-{order id}` (`authorize-{order id}-{n}` after an `INSTRUMENT_DECLINED`) |
| Capture authorization | `capture-{authorization id}` |
| Void authorization | `void-{authorization id}` |
| Reauthorize | `reauthorize-{authorization id}` |
| Refund | The engine's refund idempotency key, or `refund-{capture id}-{n}-{amount}` without one |
| Refund a capture without a liability shift | `liability-refund-{capture id}` |
| Delete a vault token | `vault-delete-{token id}` |

The Order's `invoice_id` is derived from its request id, so a retried create always sends the same body.

## Order meta

The gateway records what it learns about a payment on `orders.meta.paypal`: the PayPal Order id, mode, capture id and status, authorization (id, status, expiry, and reauthorization progress), payer email and id, funding source, card brand and last digits, 3-D Secure liability shift, decline count, and dispute summaries. The admin panel reads only this meta and never calls PayPal. `cart.meta.paypal` holds request ids, the shopper's "save" choice, and the saved card in use.

## Satellite lifecycle

The package registers with the engine's `SatelliteRegistry` as `artisanpack-ui/ecommerce-paypal`, declaring its migrations, config key, meta namespaces (`orders.meta.paypal`, `cart.meta.paypal`), tables, and uninstaller. When the engine marks the satellite uninstalled, the provider wires nothing: no routes, views, gateways, settings, or hooks. See [Installation](Installation#uninstalling).
