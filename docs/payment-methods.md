---
title: Payment Methods Overview
---

# Payment Methods

The package registers four gateways with the engine. Each is a separate checkout choice, can be switched on separately, and has its own storefront driver.

## In this section

- [PayPal Buttons](Payment-Methods-Paypal-Buttons) - PayPal, Pay Later, and Venmo, the decline restart flow, and button style
- [Card Fields](Payment-Methods-Card-Fields) - PayPal-hosted card fields, 3-D Secure, and the liability-shift policy
- [Apple Pay](Payment-Methods-Apple-Pay) - Domain registration, the domain association file, and going live
- [Google Pay](Payment-Methods-Google-Pay) - Readiness, 3-D Secure, and going live
- [Pay Later Messaging](Payment-Methods-Pay-Later-Messaging) - PayPal's Pay Later messages on product, cart, and checkout pages

## The gateway family

| Gateway key | Checkout label | Config key | Driver | PayPal `payment_source` | Saved methods |
| --- | --- | --- | --- | --- | --- |
| `paypal` | PayPal | `methods.paypal` | `paypal-buttons` | `paypal` (and Venmo, Pay Later) | With `vault.enabled` |
| `paypal-card` | Credit or debit card | `methods.card` | `paypal-card-fields` | `card` | With `vault.enabled` |
| `paypal-apple-pay` | Apple Pay | `methods.apple_pay` | `paypal-apple-pay` | `apple_pay` | No |
| `paypal-google-pay` | Google Pay | `methods.google_pay` | `paypal-google-pay` | `google_pay` | No |

All four support full and partial refunds. Only the `paypal` gateway receives webhooks: PayPal posts every event to `ecommerce/webhooks/paypal`, and that gateway reconciles checkouts placed with any of the four. See [Webhooks](Webhooks).

## How a payment runs

1. **Create.** When the shopper reaches the payment step, the gateway creates a PayPal Order on the server (`POST /v2/checkout/orders`) with the cart's items, totals, shipping address, and brand. The Order id is the engine's payment session reference.
2. **Approve.** The driver mounts PayPal's UI with the gateway's client config. The buyer approves in PayPal (the buttons, the card fields, or the wallet sheet). The browser never creates the Order: `createOrder` hands back the server's id.
3. **Confirm.** The driver checks the approved Order belongs to the session and reports it to the checkout. With `require_liability_shift` on, a card or wallet payment without a liability shift is refused here.
4. **Finalize.** The engine retrieves the session, runs its fraud check, and calls `capturePayment()`. The gateway captures (or authorizes and captures; see [Intent](Installation-Configuration#intent-capture-or-authorize)) with an idempotent `PayPal-Request-Id`, and stores the capture id, authorization, and payer details on `orders.meta.paypal`.

A `PENDING` capture (an eCheck or a review hold) places the order as paid and is settled later by webhook. A decline returns a failed result with a translated message; nothing throws.

### Session statuses

| PayPal Order status | Engine session status |
| --- | --- |
| `CREATED`, `SAVED` | `requires_payment_method` |
| `PAYER_ACTION_REQUIRED` | `requires_action` |
| `APPROVED` | `authorized` (ready for finalize) |
| `COMPLETED` with a `COMPLETED` capture | `succeeded` |
| `COMPLETED` with a `PENDING` capture | `processing` |
| `COMPLETED` with a `CREATED` authorization | `authorized` |
| `VOIDED` | `canceled` |

### Money

Amounts are sent as PayPal's decimal strings. HUF, JPY, and TWD are sent without decimals. When that rounding makes the line items and breakdown differ from the engine total, the difference goes into `discount` or `handling`, so PayPal's breakdown check passes and `amount.value` always equals the engine's total.

### Refunds

`refund()` refunds the order's capture (`POST /v2/payments/captures/{id}/refund`) with the engine's idempotency key as `PayPal-Request-Id`, `invoice_id` set to `refund-{refund id}`, `custom_id` set to the engine refund id (so the webhook matches it back), and the reason as `note_to_payer` (up to 255 characters). PayPal's declines (`CAPTURE_FULLY_REFUNDED`, `REFUND_AMOUNT_EXCEEDED`, `REFUND_TIME_LIMIT_EXCEEDED`, and others) return a failed result with a translated message. A `PENDING` refund succeeds and the `PAYMENT.CAPTURE.REFUNDED` webhook settles it. Refunds made in the PayPal dashboard are recorded on the order from the webhook.

## Without storefront-livewire

The gateways, refunds, and webhooks work without any storefront. A React or Vue storefront renders the payment step with the [JavaScript module](Extending-Javascript-Module), using the client config the engine's checkout API returns.

## Replacing a driver

The storefront's `payments.drivers` config wins over the package's drivers, whichever provider boots first. To use your own Livewire component for a PayPal driver:

```php
// config/artisanpack/ecommerce-storefront-livewire.php
'payments' => [
    'drivers' => [
        'paypal-buttons' => \App\Livewire\MyPayPalButtons::class,
    ],
],
```
