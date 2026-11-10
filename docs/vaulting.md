---
title: Saved Payment Methods
---

# Saved Payment Methods

With vaulting on, signed-in shoppers can save their PayPal account or a card at checkout and pay faster next time. PayPal keeps the payment method in its vault; the store keeps only PayPal's token id and what the shopper needs to recognize it.

## Turn it on

```php
'vault' => [
    'enabled' => true,
],
```

Or switch on **Saved payment methods** in the store settings. It needs the `paypal` or `card` method on (the settings screen refuses otherwise), and the **Vault** feature on your PayPal REST app. Apple Pay and Google Pay can't be saved.

## What is stored

| Table | Columns |
| --- | --- |
| `ecommerce_paypal_customers` | `customer_id` (the engine customer, unique), `paypal_customer_id`, `mode` |
| `ecommerce_paypal_payment_tokens` | `customer_id`, `paypal_token_id` (unique per mode), `gateway_key`, `type` (`paypal`, `card`, `venmo`), `display` (JSON: brand, last digits, expiry, email), `is_default`, `mode`, `last_used_at`, timestamps, soft deletes |

No card number, CVV, or track data is ever stored. Each customer has at most one default method per mode, whatever UI or API made the change. Sandbox and live methods are kept apart by `mode`.

## Saving during checkout

When vaulting is on and the shopper is signed in and owns the cart, the PayPal and card drivers show a "Save … for faster checkout" checkbox. PayPal only vaults a method when the Order was created asking for it, so ticking the box replaces the cart's PayPal Order with one carrying `payment_source.{paypal|card}.attributes.vault` (`store_in_vault: ON_SUCCESS`; PayPal also gets `usage_type: MERCHANT` and `customer_type: CONSUMER`), plus `attributes.customer.id` when the shopper already has a PayPal customer id.

After capture, the returned vault token id and PayPal customer id are stored. When PayPal finishes vaulting later, the `VAULT.PAYMENT-TOKEN.CREATED` webhook stores it then. Nothing is saved for guest checkouts.

## Paying with a saved method

- **PayPal.** For a shopper with a PayPal customer id, the server mints an `id_token` (`POST /v1/oauth2/token` with `response_type=id_token` and `target_customer_id`), and the SDK loads with `data-user-id-token`. PayPal then shows the saved account in the buttons. If PayPal can't mint one, checkout goes on without it.
- **Cards.** Saved cards are listed above the card fields. Choosing one pays by `vault_id`, with no card fields. See [Card Fields](Payment-Methods-Card-Fields#saved-cards).

`supportsSavedInstruments()` returns `vault.enabled` for the `paypal` and `paypal-card` gateways, and `false` for the wallets.

## The account page

With storefront-livewire installed and its routes on, the package adds a **Saved payment methods** page to the customer account:

| | |
| --- | --- |
| Path | `{account.route_prefix}/payment-methods` (default `/account/payment-methods`) |
| Route name | `artisanpack.ecommerce.account.paypal-payment-methods` |
| Middleware | The storefront's `account.middleware` (default `web`, `auth`) |
| Livewire component | `artisanpack-ecommerce-paypal-saved-methods` |
| Account menu key | `paypal-payment-methods` (position 35, icon `o-credit-card`, shown only while `vault.enabled` is on) |

It lists the shopper's methods for the current mode (the default first, then the most recently used), marks expired cards, sets the default, and removes a method after a confirmation dialog: first at PayPal, then locally. If PayPal can't be reached, nothing is removed and the shopper can try again. Every action is checked against the signed-in customer. The page renders in the storefront's layout and account shell, and is sent with `Cache-Control: no-store, private` and `X-Robots-Tag: noindex`.

To mount the list on your own page:

```blade
<livewire:artisanpack-ecommerce-paypal-saved-methods />
```

React and Vue storefronts get the menu entry from the engine's `me/account-menu` and route it themselves.

## REST endpoints

For React and Vue storefronts. They are registered on the engine's REST stack when it is on (`artisanpack.ecommerce.features.rest`), under its prefix and version (default `api/ecommerce/v1`), with its middleware, auth middleware (default `ecommerce.service-signature`, `auth:sanctum`), and problem+json errors.

| Method | Path | Route name | Does |
| --- | --- | --- | --- |
| `GET` | `me/paypal/payment-methods` | `ecommerce.api.me.paypal.payment-methods.index` | Lists the shopper's methods for the current mode |
| `DELETE` | `me/paypal/payment-methods/{token}` | `ecommerce.api.me.paypal.payment-methods.destroy` | Removes a method at PayPal, then locally. `204` on success; `502` (`paypal-unavailable`) when PayPal can't be reached |
| `POST` | `me/paypal/payment-methods/{token}/default` | `ecommerce.api.me.paypal.payment-methods.default` | Makes a method the default and returns it |

- `{token}` is the method's local numeric `id`, never PayPal's token id.
- Every route uses the `ecommerce.admin.mutate` rate policy, like the engine's own `me/*` routes. `DELETE` and `POST` also need an `Idempotency-Key` header.
- A Sanctum token needs the `ecommerce:storefront` ability (`403` otherwise). An account with no customer record is a `404` (`customer-not-found`), and a method that isn't the shopper's is a `404`.

Each method carries `id`, `type` (`paypal_payment_method`), `method` (`paypal`, `card`, `venmo`), `gateway`, `label`, `brand`, `last_digits`, `expiry`, `expired`, `email`, `is_default`, `mode`, `last_used_at`, and `created_at`. PayPal's token id never leaves the server.

```http
DELETE /api/ecommerce/v1/me/paypal/payment-methods/12
Authorization: Bearer {token}
Idempotency-Key: 6c1b8a0e-…
```

## Webhooks

| Event | Effect |
| --- | --- |
| `VAULT.PAYMENT-TOKEN.CREATED` | Stores a method saved outside checkout (in PayPal, or a save that finished after checkout) when its PayPal customer id belongs to one of the store's customers. The token is re-fetched, and only display data is kept. |
| `VAULT.PAYMENT-TOKEN.DELETED` | Soft-deletes the local method once PayPal confirms the token is gone, promoting another default. A forged event can't remove a shopper's card. |

Tokens of the other mode, or of PayPal customers the store doesn't know, are ignored.

## Erasure

- **Customer deleted.** When the engine deletes a customer (`ap.ecommerce.customer.deleted`), their local rows are removed right away, and a queued job deletes the current mode's tokens at PayPal, retrying while PayPal is unreachable. The other mode's tokens can only be removed locally.
- **Uninstall with `--purge`.** The current mode's tokens are deleted at PayPal before the engine drops the tables. See [Installation](Installation#uninstalling).
