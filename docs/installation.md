---
title: Installation Overview
---

# Installation

Everything you need to install the PayPal gateway and connect it to your PayPal account.

## In this section

- [Requirements](Installation-Requirements) - PHP, Laravel, the engine, and optional packages
- [PayPal App and Credentials](Installation-Paypal-App) - Creating sandbox and live REST apps, credentials, webhooks, and going live
- [Configuration](Installation-Configuration) - Every configuration key, its environment variable, and the store settings that override it
- [Front-End Setup](Installation-Front-End) - Tailwind sources, the published JS module, and CSP

## Install via Composer

```bash
composer require artisanpack-ui/ecommerce-paypal
```

The service provider is auto-discovered. It registers the package with the engine's `SatelliteRegistry` first; when the satellite has been uninstalled, nothing else is wired.

## Run the install command

```bash
php artisan ecommerce-paypal:install
```

It:

1. Publishes the config to `config/artisanpack/ecommerce-paypal.php`. If that file already exists it asks before overwriting it; without a terminal it keeps your file. `--force` overwrites without asking.
2. Publishes the prebuilt JS module (`dist/paypal-checkout.js`) to `public/vendor/ecommerce-paypal`, replacing an older copy.
3. Asks whether to publish and run the migrations. `--migrate` does both without asking (it runs `migrate --force`). Otherwise run `php artisan migrate` yourself; the migrations also load from the package without publishing.
4. Prints the Tailwind `@source` line, the Content-Security-Policy sources, and the next steps.

See [Artisan Commands](Advanced-Artisan-Commands).

## Publishing

| Tag | Publishes to | Use it to |
| --- | --- | --- |
| `ecommerce-paypal-config` | `config/artisanpack/ecommerce-paypal.php` | Change defaults (the install command does this) |
| `ecommerce-paypal-assets` | `public/vendor/ecommerce-paypal` | Serve `paypal-checkout.js` (the install command does this) |
| `ecommerce-paypal-migrations` | `database/migrations/ecommerce-paypal` | Publish the migrations |
| `ecommerce-paypal-views` | `resources/views/vendor/ecommerce-paypal` | Override Blade views |
| `ecommerce-paypal-lang` | `lang/vendor/ecommerce-paypal` | Override translations |

Republish the assets after every upgrade, so the browser gets the module that matches the PHP code:

```bash
php artisan vendor:publish --tag=ecommerce-paypal-assets --force
```

The config loads under `artisanpack.ecommerce-paypal`:

```php
config( 'artisanpack.ecommerce-paypal.intent' ); // 'capture'
```

Views and translations load under the `ecommerce-paypal::` namespace. A published view doesn't pick up fixes in later releases, so prefer the [extension points](Extending).

## Tables

| Table | Holds |
| --- | --- |
| `ecommerce_paypal_customers` | The PayPal vault customer id for each engine customer, per mode |
| `ecommerce_paypal_payment_tokens` | Saved payment methods: the PayPal token id, gateway, type (`paypal`, `card`, `venmo`), display data (brand, last digits, expiry, email), default flag, mode, and last use. Soft-deleted |

No card number, CVV, or other card data is ever stored. Payment details for an order are kept on the engine's order and cart meta (`orders.meta.paypal`, `cart.meta.paypal`).

## Routes

| Route | Path | When |
| --- | --- | --- |
| Webhooks (engine) | `POST ecommerce/webhooks/paypal` | Always, through the engine's `ecommerce.webhooks` route |
| `artisanpack.ecommerce-paypal.apple-pay.domain-association` | `GET /.well-known/apple-developer-merchantid-domain-association` | Unless `methods.apple_pay.serve_domain_association` is off. See [Apple Pay](Payment-Methods-Apple-Pay) |
| `artisanpack.ecommerce.account.paypal-payment-methods` | `GET {account prefix}/payment-methods` | With storefront-livewire installed and its routes on. See [Saved Payment Methods](Vaulting) |
| `ecommerce.api.me.paypal.payment-methods.*` | `{api_prefix}/{version}/me/paypal/payment-methods` | With the engine's REST API on (`artisanpack.ecommerce.features.rest`). See [Saved Payment Methods](Vaulting) |

## Uninstalling

The package registers a satellite uninstaller with the engine. `php artisan ecommerce:satellite:uninstall` keeps the PayPal data by default, so a reinstall picks up the stored customers and saved methods. With `--purge`, after the engine's confirmation, the current mode's saved tokens are deleted at PayPal, and the engine then rolls back the package's migrations (dropping both tables).

## Next steps

- [PayPal App and Credentials](Installation-Paypal-App): create the app and register the webhook
- [Configuration](Installation-Configuration): every key you can tune
- [Front-End Setup](Installation-Front-End): required before the payment UI is styled
- [Quick Start Guide](Getting-Started): install to a sandbox payment
