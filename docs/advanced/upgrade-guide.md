---
title: Upgrade Guide
---

# Upgrade Guide

The package follows [Semantic Versioning](https://semver.org/). Breaking changes only come in a major release, and each one is listed here with the steps to upgrade. Every release is also described in the [CHANGELOG](https://github.com/ArtisanPack-UI/ecommerce-paypal/blob/main/CHANGELOG.md).

## After every upgrade

1. Update the package:

   ```bash
   composer update artisanpack-ui/ecommerce-paypal
   ```

2. Republish the JS module, so the browser runs the version that matches the PHP code:

   ```bash
   php artisan vendor:publish --tag=ecommerce-paypal-assets --force
   ```

3. Run new migrations:

   ```bash
   php artisan migrate
   ```

4. Re-register the webhook, in case the release subscribes to new events:

   ```bash
   php artisan ecommerce-paypal:webhooks:register
   ```

   The command finds your webhook by its URL. If you registered it with `--url`, pass the same `--url` again: the existing webhook's event list is updated and its id stays the same. If the URL it registers differs from the existing webhook's (a changed `APP_URL`, say), PayPal creates a new webhook with a new id. Put that id in `PAYPAL_WEBHOOK_ID` (comma-separated with the old one while both still deliver), and delete the old webhook in the PayPal dashboard once deliveries move over.

5. Check the setup:

   ```bash
   php artisan ecommerce-paypal:verify-credentials
   ```

6. If you published the config, views, or translations, compare them with the package's new copies (`vendor/artisanpack-ui/ecommerce-paypal/config`, `resources/views`, `lang`). New config keys have defaults, so a published config keeps working, but you won't see the new keys until you add them.

## Upgrading to 1.0.0

1.0.0 is the first stable release; there is nothing to upgrade from. It needs `artisanpack-ui/ecommerce` 1.3 or later. Follow the [Installation](Installation) guide.

## Engine upgrades

The package depends on the engine's `PaymentGateway`, `RendersClientPayment`, and `SharesWebhookEndpoint` contracts. Upgrade the engine within its supported range (`^1.3`) and run `ecommerce:verify-satellite` if you build on the gateways yourself.

## Switching from sandbox to live

That isn't a package upgrade, but it has its own checklist. See [Going live](Installation-Paypal-App#going-live).
