---
title: Sandbox Integration Tests
---

# Sandbox Integration Tests

For contributors. Unit and feature tests answer every PayPal call with recorded fixtures (`PayPalFake`). The sandbox suite runs the same code against PayPal's real sandbox, so a change on PayPal's side (API drift) fails a build instead of a live checkout.

## What it covers

`tests/Sandbox` (Pest group `sandbox`) mirrors the engine's Stripe test-mode suite:

| Test | Flow |
| --- | --- |
| `CardCaptureFlowTest` | Intent `capture`: create a card-source Order for a real engine cart, place and capture it through `CheckoutService::finalize()`, capture again (idempotent), fetch `PAYMENT.CAPTURE.COMPLETED` from PayPal and run it through the webhook route, refund part of it with `RefundService`, then reconcile `PAYMENT.CAPTURE.REFUNDED`. The engine order is checked after each step. |
| `AuthorizeFlowTest` | Intent `authorize`: the same flow captured through the authorization, plus an Order whose authorization the engine voids when its fraud check blocks the order, reconciled from `PAYMENT.AUTHORIZATION.VOIDED`. |

Card orders need no buyer approval, so the suite runs unattended. The sandbox test card is put on the Order through the `ap.ecommerce.paypal.orderPayload` filter. That happens only in the suite, which has no browser to fill PayPal's hosted fields. Package code never sends card data.

Events come from PayPal's `GET /v1/notifications/webhooks-events`, because CI has no public URL for PayPal to deliver to. They're posted to `POST ecommerce/webhooks/paypal`, so they run through the verifier, `PayPalEventMapper`, and the engine's reconciler. Only the `verify-webhook-signature` answer is faked: an event fetched from the API carries no signature. Every other call, including the mapper re-fetching the Order, goes to the sandbox. Real signatures are covered by [recorded deliveries](#recorded-signed-deliveries).

## Running it

1. Create a sandbox REST app in the PayPal developer dashboard with **Advanced Credit and Debit Card Payments** on.
2. Give the app a webhook subscribed to the package's events. PayPal records events only for subscribed webhooks, but never needs to reach the URL, so any HTTPS URL works. The package checkout has no `artisan`, so add it in the developer dashboard: open the app, choose **Add Webhook**, enter a URL such as `https://example.com/ecommerce/webhooks/paypal`, and tick the events in `src/Webhooks/PayPalWebhookEvents.php` (`SUBSCRIBED`). From a host Laravel app set to the sandbox app's credentials (`PAYPAL_MODE=sandbox`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`), `php artisan ecommerce-paypal:webhooks:register --url=https://example.com/ecommerce/webhooks/paypal` does the same.

3. Run the suite:

   ```bash
   export PAYPAL_SANDBOX_CLIENT_ID=...
   export PAYPAL_SANDBOX_CLIENT_SECRET=...
   composer test:sandbox
   ```

Without the credentials, every sandbox test is skipped. `composer test` never runs them.

| Variable | Needed | Use |
| --- | --- | --- |
| `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_CLIENT_SECRET` | Yes | The sandbox app |
| `PAYPAL_SANDBOX_CARD_NUMBER` | No | A card that pays without 3-D Secure (default `4111111111111111`) |

Each event can take a few seconds to appear in PayPal's list. The suite polls for up to two minutes per event, then fails and names the event it was waiting for.

## In CI

`.github/workflows/sandbox.yml` runs the suite nightly on `main`, on every push to a `release/**` branch, and on demand (**Run workflow**). Its jobs use the `paypal-sandbox` GitHub environment, where the secrets are set; until they are, every sandbox test is skipped. Pull requests never get them.

## Recorded signed deliveries

`tests/Fixtures/signed-deliveries/{EVENT_TYPE}.json` holds real deliveries recorded from the sandbox: PayPal's transmission headers, the raw body, the webhook id, and the certificate it was signed with. `tests/Feature/Webhooks/RecordedDeliveriesTest.php` replays each one at its transmission time, so it runs on every pull request with no network access. Each delivery must verify exactly as it arrived and fail once its body is changed. With no recordings yet, those tests are skipped.

Refresh the recordings when PayPal changes how it signs, or when a certificate in them is about to expire. Run the recorder:

```bash
PAYPAL_SANDBOX_WEBHOOK_ID=WH-... php -S 127.0.0.1:8787 bin/record-webhook-deliveries.php
```

Then:

1. Expose it over HTTPS with any tunnel (`cloudflared tunnel --url http://127.0.0.1:8787`, `ngrok http 8787`, or `herd share`).
2. Point the sandbox webhook at the tunnel's URL.
3. Run `composer test:sandbox`, or pay in a sandbox checkout. The recorder saves one file per event type. It only saves a delivery whose signature verifies against the certificate from `PAYPAL-CERT-URL` (HTTPS on `api.sandbox.paypal.com` only).
4. Stop the server, check that `composer test` passes, and commit the files.

The bodies come from sandbox accounts only. Don't record deliveries from a live webhook.
