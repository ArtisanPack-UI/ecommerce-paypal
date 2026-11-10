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

The tables come from the package's migrations, which load automatically unless the host called the engine's `Ecommerce::ignoreMigrations()`. In that case publish them (`php artisan vendor:publish --tag=ecommerce-paypal-migrations`) and run them from where they are published, or let `php artisan ecommerce-paypal:install --migrate` do both:

```bash
php artisan migrate --path=database/migrations/ecommerce-paypal
```

No card number, CVV, or track data is ever stored. Each customer has at most one default method per mode, whatever UI or API made the change. Sandbox and live methods are kept apart by `mode`.

## Saving during checkout

When vaulting is on and the shopper is signed in and owns the cart, the PayPal and card drivers show a "Save … for faster checkout" checkbox. PayPal only vaults a method when the Order was created asking for it, so ticking the box replaces the cart's PayPal Order with one carrying `payment_source.{paypal|card}.attributes.vault` (`store_in_vault: ON_SUCCESS`; PayPal also gets `usage_type: MERCHANT` and `customer_type: CONSUMER`), plus `attributes.customer.id` when the shopper already has a PayPal customer id.

After capture, the returned vault token id and PayPal customer id are stored. When PayPal finishes vaulting later, the `VAULT.PAYMENT-TOKEN.CREATED` webhook stores it then. Nothing is saved for guest checkouts.

## Paying with a saved method

- **PayPal.** For a shopper with a PayPal customer id, the server mints an `id_token` (`POST /v1/oauth2/token` with `response_type=id_token` and `target_customer_id`), and the SDK loads with `data-user-id-token`. PayPal then shows the saved account in the buttons. If PayPal can't mint one, checkout goes on without it.
- **Cards.** Saved cards are listed above the card fields. Choosing one pays by `vault_id`, with no card fields. See [Card Fields](Payment-Methods-Card-Fields#saved-cards).

`supportsSavedInstruments()` returns `vault.enabled` for the `paypal` and `paypal-card` gateways, and `false` for the wallets.

## From API storefronts

React, Vue, and headless storefronts send the same choices as `payment_options` when they create the payment session (`POST checkout/{cart}/session`, or GraphQL `createPaymentSession`). The gateways implement the engine's `AcceptsPaymentOptions` contract (engine 1.3), and the Livewire drivers use the same options.

| Option | Type | Gateways | Does |
| --- | --- | --- | --- |
| `save_payment_method` | boolean | `paypal`, `paypal-card` | Asks PayPal to vault the account or card when the Order is captured. Leaving it out of the options means "don't save". |
| `saved_payment_method` | integer | `paypal-card` | Pays with this saved card: the `id` from `GET me/paypal/payment-methods`. No card fields are needed. |

```http
POST /api/ecommerce/v1/checkout/{cart}/session
Authorization: Bearer {token}
Idempotency-Key: 0b6e…

{ "return_url": "https://shop.test/checkout/return", "payment_options": { "save_payment_method": true } }
```

- **Who can send them.** Options apply only while vaulting is on and the cart belongs to the signed-in shopper. A guest, a signed-out shopper, the Apple Pay and Google Pay gateways, an unknown key, or a saved card on the `paypal` gateway all get `422` `payment-options-invalid`, and no PayPal Order is created.
- **Saved cards.** A card that isn't the shopper's (or is from the other mode, or is a PayPal account) is `422` `saved-payment-method-unavailable`. A card PayPal no longer holds is removed and answered with `422` `saved-payment-method-invalid`. A card PayPal still holds but declined is kept and answered with `422` `saved-payment-method-unavailable` ("This card was declined."). In each case, ask the shopper to choose another card or enter a new one.
- **Changing the choice.** Send the options again whenever the shopper changes them. The cart's PayPal Order is kept while the options match what it was created with, and replaced when they don't (ticking or unticking "save", or picking another saved card). Leave `payment_options` out, or send `null`, to keep the current Order's choices. Send `{}` to go back to a new card without saving.

A saved-card Order is often charged when it is created, so after the session call go straight to `POST checkout/{cart}/finalize` with the session's `reference`. Only show PayPal's 3-D Secure step when the session says it needs the shopper (`requires_action`). See [JavaScript Module](Extending-Javascript-Module#saved-methods-in-react-and-vue) for a React example.

## The account page

With storefront-livewire installed and its routes on, the package adds a **Saved payment methods** page to the customer account:

| | |
| --- | --- |
| Path | `{account.route_prefix}/payment-methods` (default `/account/payment-methods`) |
| Route name | `artisanpack.ecommerce.account.paypal-payment-methods` |
| Middleware | The storefront's `account.middleware` (default `web`, `auth`) |
| Livewire component | `artisanpack-ecommerce-paypal-saved-methods` |
| Account menu key | `paypal-payment-methods` (position 35, icon `o-credit-card`, shown only while `vault.enabled` is on) |

It lists the shopper's methods for the current mode (the default first, then the most recently used), marks expired cards, sets the default, and removes a method after a confirmation dialog: first at PayPal, then locally. If PayPal can't be reached, nothing is removed and the shopper can try again. Every action is checked against the signed-in customer. The page renders in the storefront's layout and account shell, and is sent with `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`.

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
- A Sanctum token needs the `ecommerce:storefront` ability (`403` otherwise). For an account with no customer record, `GET` returns an empty list, while `DELETE` and `POST` are a `404` (`customer-not-found`). A method that isn't the shopper's is a `404`.

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

- **Customer deleted.** When the engine deletes a customer (`ap.ecommerce.customer.deleted`), their local rows are removed right away, and a queued job deletes the current mode's tokens at PayPal. The job's payload is encrypted, since it holds PayPal token ids. It tries every token: a token PayPal no longer has counts as deleted, and one PayPal refuses for good is logged at `error` and skipped. Tokens that failed because PayPal was unreachable are sent again 10 minutes later, for up to 5 rounds, before the job gives up and logs the count at `critical`. The other mode's tokens can only be removed locally, and without PayPal credentials none can be deleted at PayPal; both cases log a `warning` with the mode and the number of tokens left at PayPal (never their ids), so you can delete them in that PayPal account.
- **Missed deletions.** The daily `ecommerce-paypal:prune-vault` command erases the vault rows of customers the engine no longer has, in the same way, for when the hook was missed (the package was uninstalled at the time, the customer was deleted without the engine, or the listener failed). See [Artisan Commands](Advanced-Artisan-Commands#ecommerce-paypalprune-vault).
- **Removed methods.** A method the shopper or PayPal removed is soft-deleted, so its display data (brand, last digits, expiry, email) stays until the same command deletes rows removed more than `vault.prune_after_days` days ago (default 30, `PAYPAL_VAULT_PRUNE_AFTER_DAYS`). A method removed this way never comes back when the same PayPal Order is recorded again.
- **Uninstall with `--purge`.** The current mode's tokens are deleted at PayPal before the engine drops the tables. If PayPal can't delete any of them, the uninstall stops and nothing is dropped; run it again. See [Installation](Installation#uninstalling).
