---
title: Extending Overview
---

# Extending

Host applications and other satellites change the PayPal gateway through filters and actions, and storefronts for other front-end stacks build on the shared JavaScript module. Nothing here requires publishing views.

## In this section

- [Hooks Reference](Extending-Hooks-Reference) - Every filter and action the package fires, and the engine hooks it relies on
- [Availability](Extending-Availability) - Hiding or narrowing PayPal methods per cart, currency, or customer
- [JavaScript Module (React and Vue)](Extending-Javascript-Module) - `paypal-checkout.js` and its exported functions

## At a glance

| Hook | Type | Use it to |
| --- | --- | --- |
| `ap.ecommerce.paypal.orderPayload` | filter | Change the PayPal Order body (description, custom fields) |
| `ap.ecommerce.paypal.clientOptions` | filter | Change button style or SDK settings per cart |
| `ap.ecommerce.paypal.supportedCurrencies` | filter | Narrow or widen the currencies a method is offered in |
| `ap.ecommerce.paypal.captured` | action | React to a capture (once per order) |
| `ap.ecommerce.paypal.captureDenied` | action | React when a pending capture is denied |
| `ap.ecommerce.paypal.authorizationExpiring` | action | Capture or follow up before an authorization expires |
| `ap.ecommerce.paypal.disputeCreated` / `disputeUpdated` / `disputeResolved` | action | Apply your dispute policy |
| `ap.ecommerce.paypal.webhookEvent` | action | Handle any verified PayPal event yourself |
| `ap.ecommerce.payment.availableGateways` (engine) | filter | Hide PayPal methods for a cart |
| `ap.ecommerce.payment.clientConfig` (engine) | filter | Change any gateway's whole client config |

```php
use ArtisanPackUI\Ecommerce\Models\Order;
use Illuminate\Support\Facades\Log;

public function boot(): void
{
    addAction( 'ap.ecommerce.paypal.captured', function ( Order $order, string $captureId, string $status ): void {
        Log::info( 'PayPal capture recorded.', [ 'order' => $order->order_number, 'capture' => $captureId, 'status' => $status ] );
    }, 10, 3 );
}
```

## Other extension points

- **Payment drivers.** The Livewire drivers register into storefront-livewire's `PaymentDriverRegistry` under `paypal-buttons`, `paypal-card-fields`, `paypal-apple-pay`, and `paypal-google-pay`. An entry in the storefront's `payments.drivers` config replaces one. See [Payment Methods](Payment-Methods#replacing-a-driver).
- **Account menu.** The saved payment methods entry is `paypal-payment-methods` in the engine's `AccountMenuRegistry`. When an entry with that key is already registered as the package boots, the package leaves yours in place.
- **Admin order panel.** The panel is `paypal` in admin-livewire's `OrderPanelRegistry`. See [Admin Order Panel](Advanced-Admin-Order-Panel).
- **Views and translations.** Publish them with the `ecommerce-paypal-views` and `ecommerce-paypal-lang` tags. Prefer hooks: a published view doesn't get later fixes.
- **Order meta.** Everything the package records about a payment is on `orders.meta.paypal` (PayPal Order id, capture and authorization ids, payer details, liability shift, disputes). Read it in your own listeners; don't write to it.
