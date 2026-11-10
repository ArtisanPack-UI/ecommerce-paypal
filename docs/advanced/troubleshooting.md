---
title: Troubleshooting
---

# Troubleshooting

## Start here

```bash
php artisan ecommerce-paypal:verify-credentials
```

It checks the mode, the access token, the enabled methods against your base currency, the webhook, and the Apple Pay domain file, and says which one fails. See [Artisan Commands](Advanced-Artisan-Commands#ecommerce-paypalverify-credentials).

## PayPal debug ids

Every PayPal API response carries a `PayPal-Debug-Id` header. PayPal support asks for it to find a request in their logs. The package:

- logs it on **every** failed call, whether or not debug logging is on, as a `warning` "PayPal API call failed." with `method`, `path` (vault token ids masked), `mode`, `attempt`, `status`, `name`, `issue`, and `debug_id`;
- carries it on `PayPalApiException::$debugId` (with `$status`, `$name`, `$details`, and `issue()`), and in `$exception->context()` for your own logs;
- prints it in `ecommerce-paypal:webhooks:register` and `verify-credentials` errors;
- logs it with webhook verification failures that involved PayPal (`certificate_unavailable`, `verification_unavailable`).

Search your logs for `debug_id` and quote it, with the mode and time, when you contact PayPal.

```php
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;

try {
    // …
} catch ( PayPalApiException $exception ) {
    report( $exception );
    Log::error( 'PayPal refused the request.', $exception->context() ); // status, name, issue, debug_id
}
```

`$status` is `0` when no response arrived (a timeout or connection error). `isRetryable()` is true for those, `429`s, and `5xx`s.

## Logging

```dotenv
PAYPAL_LOG_CHANNEL=paypal
PAYPAL_LOG_DEBUG=true
```

| Setting | Effect |
| --- | --- |
| `logging.channel` (`PAYPAL_LOG_CHANNEL`) | Where PayPal logs go. `null` uses the default channel. Define the channel in `config/logging.php`. |
| `logging.debug` (`PAYPAL_LOG_DEBUG`) | Also log each request ("PayPal API request.") and response ("PayPal API response.") at `debug` level with method, path, headers, body, status, and debug id. |

Bodies and headers are redacted before they are logged: credentials, tokens, card data, vault ids, and payer personal data are replaced with `[redacted]`. See [Security and CSP](Advanced-Security-Csp#logging-and-redaction). Turn debug logging off again when you're done.

## Common problems

### PayPal doesn't appear at checkout

- PayPal isn't enabled (`PAYPAL_ENABLED` or the **Enable PayPal** setting), or the client id or secret is empty. The gateways only register when all three are set.
- The method is switched off (`methods.*.enabled`), or doesn't take the cart's currency. See [supported currencies](Installation-Configuration#supported-currencies).
- With storefront-livewire: the driver shows "This payment method isn't available." when there is no client id or Order. Check the logs for a failed Order create.
- The browser hid it: Apple Pay needs Safari on an Apple device and a registered domain; Google Pay needs `isReadyToPay`; Venmo and Pay Later depend on the buyer's country. The status region says why.
- A stale config cache: run `php artisan config:clear`.

### The buttons don't render, or render unstyled

- `public/vendor/ecommerce-paypal/paypal-checkout.js` is missing or old. Run `php artisan vendor:publish --tag=ecommerce-paypal-assets --force`.
- A Content-Security-Policy blocks PayPal. Check the browser console and add the [CSP sources](Advanced-Security-Csp#content-security-policy).
- The views aren't in your Tailwind sources. See [Front-End Setup](Installation-Front-End).

### "PayPal refused the credentials"

Sandbox and live credentials are separate. Check `PAYPAL_MODE` matches the app the client id and secret come from. The settings screen refuses to save a live setup PayPal won't issue a token for (`paypal-live-credentials-invalid`).

### Webhooks are refused

Check the log for the refusal code, then [Webhooks](Webhooks#verification):

- `webhook_id_missing` or `signature_mismatch`: `PAYPAL_WEBHOOK_ID` is empty or not the id of the webhook that delivered (a sandbox id in live mode, or an old id after re-registering). Run `ecommerce-paypal:webhooks:register` and update it.
- `stale_transmission`: the delivery is older than `webhook_tolerance_seconds` (3 days by default), or the server clock is far off. Sync the clock with NTP, and check the setting wasn't lowered.
- `invalid_cert_url`: a delivery for the other mode, or not from PayPal.
- `use_paypal_endpoint`: the webhook points at a sibling gateway's URL. Point it at `ecommerce/webhooks/paypal`.

Webhooks verify but nothing happens on the order: check a queue worker is running for the engine's webhook queue.

### `instrument_declined` after approval

PayPal declined the funding source at capture. The checkout re-mounts the buttons on the same Order so the buyer can pick another source. See [PayPal Buttons](Payment-Methods-Paypal-Buttons#when-paypal-declines-the-funding-source).

### "Your bank couldn't verify this card"

The 3-D Secure result was `NO` or `UNKNOWN` (or, with `SCA_ALWAYS`, a new card in the card fields came back with no result) and `methods.card.require_liability_shift` is on. The shopper can try another card. Turn the setting off to accept those payments at your own risk.

### An order shows paid but the money hasn't arrived

The capture is `PENDING` (an eCheck or a PayPal review). The order's timeline has a `paypal.capture_pending` entry with the reason, and `orders.meta.paypal.capture_status` and `pending_reason` hold it. `PAYMENT.CAPTURE.COMPLETED` or `DENIED` settles it; a denial sets the payment status to `failed`, adds a staff note, and fires `ap.ecommerce.paypal.captureDenied`. See [Webhooks](Webhooks#denied-pending-captures).

### A dashboard refund isn't on the order

Refunds made in PayPal are recorded from the `PAYMENT.CAPTURE.REFUNDED` webhook, once the refund is `COMPLETED`. If the engine refuses it (an over-refund or currency mismatch), a `paypal.refund_not_recorded` timeline entry is written instead.

### Apple Pay domain verification fails

Run `verify-credentials`. The file must be served over HTTPS at the domain root without redirects, as plain text. See [Apple Pay](Payment-Methods-Apple-Pay).

## Contacting PayPal

PayPal's [merchant technical support](https://www.paypal.com/merchantsupport) needs the debug id, the mode, the PayPal Order or capture id (shown in the [admin order panel](Advanced-Admin-Order-Panel)), and the time of the request.
