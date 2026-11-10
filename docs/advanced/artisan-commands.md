---
title: Artisan Commands
---

# Artisan Commands

The package registers four commands (in the console only).

| Command | Purpose |
| --- | --- |
| `ecommerce-paypal:install` | Publish the config and JS module, offer the migrations, print the setup lines |
| `ecommerce-paypal:verify-credentials` | Check the credentials, methods, currency, webhook, and Apple Pay domain file |
| `ecommerce-paypal:webhooks:register` | Create or update the PayPal webhook and print its id |
| `ecommerce-paypal:authorizations:reauthorize` | Reauthorize uncaptured authorizations and flag expiring ones (scheduled daily) |

## `ecommerce-paypal:install`

```bash
php artisan ecommerce-paypal:install [--force] [--migrate]
```

| Option | Effect |
| --- | --- |
| `--force` | Overwrite a published config file without asking. |
| `--migrate` | Publish and run the migrations without asking (runs `migrate --force`). |

Steps:

1. Publishes the config (`ecommerce-paypal-config`). An existing file is kept unless you confirm or pass `--force`; without a terminal it is always kept.
2. Publishes the JS module (`ecommerce-paypal-assets`), always overwriting.
3. Asks whether to publish (`ecommerce-paypal-migrations`) and run the migrations. Without a terminal and without `--migrate`, it skips them.
4. Prints the Tailwind `@source` line, the Content-Security-Policy sources, and the next steps (set the credentials, register the webhook, verify, enable).

## `ecommerce-paypal:verify-credentials`

```bash
php artisan ecommerce-paypal:verify-credentials [--url=]
```

| Option | Effect |
| --- | --- |
| `--url=` | The URL the webhook should deliver to, when it isn't this app's `ecommerce/webhooks/paypal` route (a proxy, say). |

| Check | Passes when |
| --- | --- |
| Mode | (informational: Sandbox or Live) |
| Access token | `PAYPAL_CLIENT_ID` and `PAYPAL_CLIENT_SECRET` are set and PayPal issues a token for them in this mode. A failure says whether PayPal refused the credentials or couldn't be reached (with the debug id). |
| Payment methods | At least one method is enabled, and each enabled method takes the store's base currency (`artisanpack.ecommerce.base_currency`). |
| Webhook | `PAYPAL_WEBHOOK_ID` is set, and each id exists at PayPal in this mode, delivers to the expected URL, and is subscribed to every event the package handles (or to `*`). Skipped when there is no access token. |
| Apple Pay domain file | Apple Pay is off (skipped), or the app is on HTTPS and `{origin}/.well-known/apple-developer-merchantid-domain-association` returns the file without a redirect. |

Exits `0` when every check passes and `1` otherwise.

## `ecommerce-paypal:webhooks:register`

```bash
php artisan ecommerce-paypal:webhooks:register [--url=]
```

| Option | Effect |
| --- | --- |
| `--url=` | Register this URL instead of the app's `ecommerce/webhooks/paypal` route (built from `APP_URL`). |

It lists the app's webhooks at PayPal. If one already delivers to the URL, it updates that webhook's events; otherwise it creates one. Either way the webhook is subscribed to exactly the events on [Webhooks](Webhooks). It prints the mode, the webhook id, the event count, and a line to paste into `.env`:

```text
    PAYPAL_WEBHOOK_ID=1AB23456CD789012E
```

and warns when `PAYPAL_WEBHOOK_ID` doesn't include that id yet. It fails when the URL is invalid, when the URL isn't HTTPS in live mode, when the credentials are missing, or when PayPal refuses (with PayPal's debug id). PayPal allows a limited number of webhooks per app; delete unused ones in the dashboard.

## `ecommerce-paypal:authorizations:reauthorize`

```bash
php artisan ecommerce-paypal:authorizations:reauthorize
```

Checks every PayPal-paid order with an authorization on `orders.meta.paypal`:

- an uncaptured (`CREATED`) authorization past its 3-day honor period, not yet reauthorized and still valid, is reauthorized once, and a note is added to the order;
- an authorization within 2 days of expiring fires `ap.ecommerce.paypal.authorizationExpiring` once.

It prints the totals (`checked`, `reauthorized`, `expiring`, `failed`) and exits `1` when any reauthorization failed. It does nothing while PayPal is disabled. The package schedules it `daily()` with `withoutOverlapping()`; make sure the scheduler runs. See [Intent](Installation-Configuration#intent-capture-or-authorize).

## Engine commands

| Command | Use with PayPal |
| --- | --- |
| `ecommerce:reconcile-payments` | Polls PayPal for checkouts and pending orders that went quiet (a missed webhook). Scheduled by the engine. |
| `ecommerce:satellite:uninstall` | Uninstalls the package; `--purge` deletes the current mode's vault tokens at PayPal and drops the tables. |
| `ecommerce:verify-satellite` | Runs the engine's contract checks against the four gateways (`composer ecommerce:verify-satellite` in this repo). |
