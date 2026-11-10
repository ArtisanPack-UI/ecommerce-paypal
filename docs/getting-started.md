---
title: Getting Started
---

# Getting Started

Get from `composer require` to a PayPal sandbox payment. This assumes the `artisanpack-ui/ecommerce` engine (1.3 or later) is installed and migrated, and that you have a PayPal developer account.

## 1. Install

```bash
composer require artisanpack-ui/ecommerce-paypal
php artisan ecommerce-paypal:install
```

The service provider is auto-discovered and registers the package with the engine's satellite registry. The install command:

- publishes `config/artisanpack/ecommerce-paypal.php`;
- publishes the prebuilt `paypal-checkout.js` to `public/vendor/ecommerce-paypal`;
- offers to publish and run the migrations (pass `--migrate` to skip the question);
- prints the Tailwind `@source` line, the Content-Security-Policy sources, and the next steps.

If you skipped the migrations, run `php artisan migrate`. See [Installation](Installation).

## 2. Add your sandbox credentials

Create a REST app in the [PayPal developer dashboard](https://developer.paypal.com/dashboard/applications/sandbox) (see [PayPal App and Credentials](Installation-Paypal-App)) and copy its client id and secret into `.env`:

```dotenv
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=your-sandbox-client-id
PAYPAL_CLIENT_SECRET=your-sandbox-secret
```

## 3. Register the webhook

PayPal must be able to reach your app over the internet. Use a tunnel (for example `ngrok` or `herd share`) during local development and set `APP_URL` to it, or pass `--url`.

```bash
php artisan ecommerce-paypal:webhooks:register
```

The command subscribes `ecommerce/webhooks/paypal` to the events the package handles and prints the webhook id. Add it to `.env`:

```dotenv
PAYPAL_WEBHOOK_ID=1AB23456CD789012E
```

Webhooks are processed on the engine's webhook queue, so run a queue worker.

## 4. Check the setup

```bash
php artisan ecommerce-paypal:verify-credentials
```

It checks the access token, the enabled methods against your base currency, the webhook, and (with Apple Pay on) the domain association file. It exits non-zero when a check fails.

## 5. Turn PayPal on

```dotenv
PAYPAL_ENABLED=true
```

Or switch on **Enable PayPal** in the store settings (the **PayPal** group). The gateways register only when PayPal is enabled and both the client id and secret are set.

## 6. Show it at checkout

With `artisanpack-ui/ecommerce-storefront-livewire` installed, the PayPal buttons appear in the payment step with no extra work. Add the package's views to your Tailwind sources (the install command prints the line):

```css
@source "../../vendor/artisanpack-ui/ecommerce-paypal/resources/views/**/*.blade.php";
```

Pay with a [PayPal sandbox buyer account](https://developer.paypal.com/dashboard/accounts) to place a test order.

React and Vue storefronts mount PayPal with the shared JS module instead. See [JavaScript Module](Extending-Javascript-Module).

## Next steps

- [Configuration](Installation-Configuration): turn on cards, Apple Pay, Google Pay, Pay Later messages, and saved payment methods
- [Payment Methods](Payment-Methods): what each method needs before you go live
- [Security and CSP](Advanced-Security-Csp): the sources to allow if your site sends a Content-Security-Policy
- [Upgrade Guide](Advanced-Upgrade-Guide) and [PayPal App and Credentials](Installation-Paypal-App): going live
