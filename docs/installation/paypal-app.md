---
title: PayPal App and Credentials
---

# PayPal App and Credentials

The package talks to PayPal's REST API with a REST app's client id and secret. Sandbox and live use separate apps with separate credentials, and a separate webhook.

## Sandbox

1. Sign in to the [PayPal developer dashboard](https://developer.paypal.com/dashboard/) and switch the toggle to **Sandbox**.
2. Open **Apps & Credentials** and choose **Create App**. Pick the **Merchant** app type and a sandbox business account.
3. Copy the **Client ID** and **Secret** into `.env`:

   ```dotenv
   PAYPAL_MODE=sandbox
   PAYPAL_CLIENT_ID=your-sandbox-client-id
   PAYPAL_CLIENT_SECRET=your-sandbox-secret
   ```

4. In the app's features, make sure the methods you plan to offer are switched on: **Advanced Credit and Debit Card Payments** for card fields, **Apple Pay** and **Google Pay** for the wallets, and **Vault** for saved payment methods.
5. Under **Sandbox Accounts**, use (or create) a personal account to pay with at checkout. PayPal's sandbox test cards work in the card fields.

## Register the webhook

PayPal posts every event for the app to one URL. The engine serves it at `POST ecommerce/webhooks/paypal`, and the package subscribes it to the events it handles:

```bash
php artisan ecommerce-paypal:webhooks:register
```

The command builds the URL from `APP_URL`, creates the webhook (or updates the events of the one already registered for that URL), and prints its id:

```dotenv
PAYPAL_WEBHOOK_ID=1AB23456CD789012E
```

Set it, or every delivery is rejected. Pass `--url=` when PayPal reaches you through a different address (a tunnel or a proxy). You can also create the webhook by hand in the dashboard: subscribe it to the events listed on [Webhooks](Webhooks).

Run the command again after an upgrade that adds events. `ecommerce-paypal:verify-credentials` tells you when the webhook is missing any.

## Check the setup

```bash
php artisan ecommerce-paypal:verify-credentials
```

| Check | Fails when |
| --- | --- |
| Mode | (informational) |
| Access token | The client id or secret is missing, PayPal refuses them for the mode, or PayPal can't be reached |
| Payment methods | No method is enabled, or an enabled method doesn't take the store's base currency (`artisanpack.ecommerce.base_currency`) |
| Webhook | `PAYPAL_WEBHOOK_ID` is empty, an id isn't found at PayPal in this mode, it delivers to another URL than the webhook route (or `--url`), or it is missing subscribed events |
| Apple Pay domain file | With Apple Pay on: the app isn't on HTTPS, or `/.well-known/apple-developer-merchantid-domain-association` doesn't serve the file |

It exits non-zero when any check fails, so you can run it in a deploy script.

## Going live

1. Switch the dashboard toggle to **Live** and create a live REST app on your PayPal Business account. Live and sandbox credentials are not interchangeable.
2. For card fields, Apple Pay, and Google Pay, the account must be approved for those features. Check the app's features in the live dashboard; some need an application and review by PayPal. See the Apple Pay and Google Pay go-live steps on [Apple Pay](Payment-Methods-Apple-Pay) and [Google Pay](Payment-Methods-Google-Pay).
3. Update `.env`:

   ```dotenv
   PAYPAL_MODE=live
   PAYPAL_CLIENT_ID=your-live-client-id
   PAYPAL_CLIENT_SECRET=your-live-secret
   ```

4. Make sure `APP_URL` is your HTTPS address, then register the live webhook and set its id:

   ```bash
   php artisan ecommerce-paypal:webhooks:register
   ```

5. Run `php artisan ecommerce-paypal:verify-credentials`.
6. Clear the config cache if you cache config (`php artisan config:cache`).

When PayPal is enabled in live mode, the store settings screen refuses to save if PayPal won't issue a live token for the credentials. That check can't run while PayPal is unreachable or the credentials are incomplete, so always run `verify-credentials` after switching.

### Mode-specific data

Everything PayPal stores is per mode. Saved payment methods and PayPal customer ids are stored with their mode, so sandbox methods aren't offered in live mode (and the other way round). The admin order panel links each order to the dashboard of the mode it was paid in.

## Rotating credentials

- **Client secret.** Generate a new secret on the app, update `PAYPAL_CLIENT_SECRET`, and deploy. Access tokens are cached per mode and client id; when PayPal rejects a cached token (`401`), the client fetches a new one with the new secret and retries once.
- **Webhook.** To move to a new webhook without dropping deliveries, list both ids while the old one is still registered:

  ```dotenv
  PAYPAL_WEBHOOK_ID=OLDID123,NEWID456
  ```

  A delivery signed for either id is accepted. Remove the old id (and the old webhook in the dashboard) once the new one is receiving events.

## Where credentials live

The client secret and webhook ids are only read from config and the environment. The store settings screen lists them as configured or not, and never shows or stores them. The client id is public: the PayPal JS SDK loads with it. See [Configuration](Installation-Configuration).
