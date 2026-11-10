---
title: Webhooks
---

# Webhooks

PayPal posts every event for your REST app to one URL. The engine serves it at `POST ecommerce/webhooks/paypal` (route `ecommerce.webhooks`), and the `paypal` gateway verifies and maps each delivery. The engine handles deduplication, rate limiting (`ecommerce.webhook.inbound`), its `ap.ecommerce.webhook_received` hooks, and reconciliation of checkouts the shopper never finished in the browser.

Because the `paypal` gateway implements the engine's `SharesWebhookEndpoint`, one endpoint reconciles checkouts placed with all four gateway keys. A delivery posted to a sibling's URL (`ecommerce/webhooks/paypal-card`, …) is refused with `use_paypal_endpoint`.

## Setup

```bash
php artisan ecommerce-paypal:webhooks:register
```

Set the printed id as `PAYPAL_WEBHOOK_ID`. Without a webhook id, every delivery is refused (`webhook_id_missing`). See [PayPal App and Credentials](Installation-Paypal-App) for rotating the webhook, and [Artisan Commands](Advanced-Artisan-Commands) for the options.

Run a queue worker for the engine's webhook queue (`artisanpack.ecommerce.webhooks.connection` and `.queue`): the reconciliation job and this package's side effects run there.

## Subscribed events

`ecommerce-paypal:webhooks:register` subscribes the webhook to exactly these events:

| Event | Payment outcome reported to the engine | Side effect (queued) |
| --- | --- | --- |
| `CHECKOUT.ORDER.APPROVED` | None (finalize runs from the browser) | None |
| `PAYMENT.CAPTURE.COMPLETED` | `succeeded` | Settles a pending capture on a placed order (`paypal.capture_completed` timeline entry) |
| `PAYMENT.CAPTURE.PENDING` | None | `paypal.capture_pending` timeline entry with the reason |
| `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` | `failed`, once PayPal confirms it | For a pending capture on a placed order: records the status, writes `paypal.capture_denied`, adds a staff note, fires `ap.ecommerce.paypal.captureDenied` |
| `PAYMENT.AUTHORIZATION.CREATED` | `succeeded` | None |
| `PAYMENT.AUTHORIZATION.VOIDED` | `failed`, once PayPal confirms it | None |
| `PAYMENT.CAPTURE.REFUNDED` | `refunded`, with the refund id | Records a refund made in PayPal on the order |
| `PAYMENT.CAPTURE.REVERSED` | `refunded` | Records the reversal as a refund and writes `paypal.capture_reversed` |
| `CUSTOMER.DISPUTE.CREATED`, `.UPDATED`, `.RESOLVED` | None | Order note, timeline entry, summary on `orders.meta.paypal.disputes`, and the dispute hooks |
| `VAULT.PAYMENT-TOKEN.CREATED`, `.DELETED` | None | Syncs saved payment methods. See [Saved Payment Methods](Vaulting#webhooks) |

The session reference is the PayPal Order id: the resource id for `CHECKOUT.ORDER.*`, and `supplementary_data.related_ids.order_id` for payment events.

**Trust nothing in the body.** PayPal signs a CRC of the body, not the body itself. A `succeeded` outcome only makes the engine run finalize, which loads the Order from PayPal before capturing. A `failed` outcome is only reported after the capture or authorization is fetched and PayPal confirms it. Every side effect re-fetches its resource (capture, refund, dispute, or token) from PayPal first.

## Verification

Each delivery is verified before anything else. It is refused, with a distinct code, when:

| Code | Cause |
| --- | --- |
| `missing_headers` | A `PAYPAL-TRANSMISSION-ID`, `-TRANSMISSION-TIME`, `-TRANSMISSION-SIG`, `PAYPAL-CERT-URL`, or `PAYPAL-AUTH-ALGO` header is missing |
| `unsupported_algorithm` | The algorithm isn't `SHA256withRSA` |
| `stale_transmission` | The transmission time is unreadable or more than 5 minutes off |
| `invalid_cert_url` | The certificate URL isn't HTTPS on PayPal's certificate host for the mode (`api.paypal.com` live, `api.sandbox.paypal.com` sandbox) |
| `webhook_id_missing` | No webhook id is configured |
| `invalid_payload` | The body isn't a PayPal event |
| `certificate_unavailable` | The certificate couldn't be downloaded (PayPal redelivers) |
| `certificate_expired` | The certificate is outside its validity dates |
| `certificate_untrusted` | The certificate can't be read or wasn't issued to PayPal |
| `signature_mismatch` | No configured webhook id verifies the signature |
| `verification_unavailable` | `postback` mode: PayPal's verification endpoint couldn't be reached (PayPal redelivers) |
| `use_paypal_endpoint` | Posted to a sibling gateway's URL |

### `local` (default)

The certificate is fetched through the PayPal client (TLS-verified), checked, and cached until it expires. The signature over `transmission_id|transmission_time|webhook_id|crc32(body)` is checked with OpenSSL for each configured webhook id. No API call per event, and it keeps working while PayPal's API is degraded.

### `postback`

```dotenv
PAYPAL_WEBHOOK_VERIFICATION=postback
```

Each delivery is sent to PayPal's `POST /v1/notifications/verify-webhook-signature` instead, so PayPal decides. It costs one API call per event, and deliveries are refused (and redelivered by PayPal) while that endpoint is unreachable.

A failure logs its code and the transmission id (and PayPal's debug id when there is one), never the body.

## Processing

1. The engine verifies the delivery through the `paypal` gateway, dedupes it, and queues reconciliation for a payment outcome.
2. For a new, verified delivery, the engine fires `ap.ecommerce.gateway.paypal.webhook_received`. The package then:
   - queues `ProcessPayPalWebhook` for events with side effects. The job carries only the event id, type, and resource id, never the payer's personal data, and retries 5 times (after 30 s, 2 min, 10 min, and 30 min) while PayPal is unreachable;
   - fires `ap.ecommerce.paypal.webhookEvent` `(string $eventType, array $event, WebhookResult $result)` for every verified event, whether or not the package acts on it. A listener that throws is reported and doesn't fail the delivery.

## Disputes and reversals

Dispute events are matched to an order through the disputed capture ids; disputes about captures no order recorded are ignored. For a match, the package adds a translated system note with the reason, amount, respond-by date, and a Resolution Center link; writes a `paypal.dispute_created`, `_updated`, or `_resolved` timeline entry; keeps a summary on `orders.meta.paypal.disputes.{dispute id}`; and fires `ap.ecommerce.paypal.disputeCreated`, `disputeUpdated`, or `disputeResolved` `(Order $order, array $dispute)`.

Disputes don't change the order status. That's store policy: apply it from the hooks or a kanban automation.

```php
use ArtisanPackUI\Ecommerce\Models\Order;

addAction( 'ap.ecommerce.paypal.disputeCreated', function ( Order $order, array $dispute ): void {
    Notification::route( 'mail', config( 'mail.from.address' ) )->notify( new DisputeOpened( $order, $dispute ) );
}, 10, 2 );
```

A reversal (a chargeback PayPal settled against you) is recorded as a refund. When the engine refuses to record a refund (an over-refund or a currency mismatch), a `paypal.refund_not_recorded` timeline entry is written for staff to reconcile.

See the [Hooks Reference](Extending-Hooks-Reference) for every argument.

## Testing locally

PayPal can't reach `localhost`. Use a tunnel and register it with `--url`:

```bash
php artisan ecommerce-paypal:webhooks:register --url=https://abc123.ngrok.app/ecommerce/webhooks/paypal
```

The PayPal developer dashboard's **Webhooks simulator** sends sample events that aren't signed for your webhook id, so expect them to be refused (`signature_mismatch`). Trigger real events by paying in the sandbox instead. PayPal's **Webhooks events** page in the dashboard lists every delivery and lets you resend one.
