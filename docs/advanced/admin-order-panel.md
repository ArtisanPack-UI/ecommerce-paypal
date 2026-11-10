---
title: Admin Order Panel
---

# Admin Order Panel

With `artisanpack-ui/ecommerce-admin-livewire` installed, the package adds a **PayPal** card to the side column of the admin order screen. It answers "did this payment go through?" without opening the PayPal dashboard.

| | |
| --- | --- |
| Registry | admin-livewire's `OrderPanelRegistry` |
| Panel key | `paypal` |
| Column / position | `side` / `60` |
| Livewire component | `artisanpack-ecommerce-paypal-order-panel` |

## What it shows

| Row | From |
| --- | --- |
| Mode | Sandbox or Live, as the order was paid |
| PayPal Order | The PayPal Order id |
| Capture | The capture id, linked to the transaction in the PayPal dashboard for the order's mode |
| Authorization | The authorization id (linked), its status, and when it expires (or expired) |
| Payer email | The PayPal payer's email |
| Funding source | PayPal, Venmo, Card, Apple Pay, or Google Pay, with the card brand and last digits |
| Liability shift | The 3-D Secure result: Possible (3-D Secure passed), Yes, No, or Unknown |
| Disputes | Each dispute's reason, status, outcome, respond-by date, and a Resolution Center link |

Everything comes from `orders.meta.paypal`, so the panel never calls PayPal and loads instantly. For an order not paid with PayPal it renders nothing. It re-renders when another panel changes the order (the `ecommerce-admin-order-updated` event, after a refund or capture).

Links open PayPal in a new tab and say so to screen readers. Each link goes to the dashboard for the mode the order was paid in, even after the store has switched modes.

## Access

Mounting the panel, and every Livewire request it makes, needs the engine's `order.view` ability on the order. A shopper owning the order isn't enough. Without it the panel answers `403`.

## Mounting it elsewhere

```blade
<livewire:artisanpack-ecommerce-paypal-order-panel :order="$order" />
```

`order` takes an `Order` or its id. The same `order.view` check applies.

## Removing or replacing it

The package registers the panel when admin-livewire's `OrderPanelRegistry` is resolved, and skips it when a panel with the `paypal` key is already there. To remove it, unregister it after that:

```php
use ArtisanPackUI\EcommerceAdminLivewire\Registries\OrderPanelRegistry;

$this->app->afterResolving( OrderPanelRegistry::class, function ( OrderPanelRegistry $panels ): void {
    $panels->unregister( 'paypal' );
} );
```

To replace it, follow the `unregister()` with `$panels->register( 'paypal', 'your-panel-component', 'side', 60 )` in the same callback.
