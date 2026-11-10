---
title: Requirements
---

# Requirements

## Required

| Requirement | Version | Why |
| --- | --- | --- |
| PHP | 8.3+ | |
| `ext-openssl` | any | Local webhook signature verification |
| Laravel (`illuminate/support`, `illuminate/http`) | 12 or 13 | |
| `artisanpack-ui/ecommerce` | 1.3+ (`^1.3`) | `PaymentGateway`, `RendersClientPayment`, and `SharesWebhookEndpoint` (added in engine 1.3), the registries, and the settings screen |
| `artisanpack-ui/core`, `artisanpack-ui/hooks` | 1.0+ | Ecosystem baseline and the hooks |

The engine's REST stack (Sanctum) is only needed for the saved payment methods REST endpoints.

## Optional

These are detected at runtime. Install them for the parts you need:

| Package | Enables |
| --- | --- |
| `artisanpack-ui/ecommerce-storefront-livewire` | The Livewire payment drivers, the saved payment methods account page, and the live Pay Later message that follows the cart total |
| `artisanpack-ui/ecommerce-admin-livewire` | The PayPal order panel and the store settings screen |
| `livewire/livewire` | Required by both of the above (they pull it in). On its own it enables the live Pay Later message component |

Without a storefront package, only the engine-side code loads: the gateways, webhooks, commands, and REST endpoints. React and Vue storefronts use the [JavaScript module](Extending-Javascript-Module) and the REST endpoints.

## Front end

The package ships no CSS build. Its views use Tailwind CSS 4 and daisyUI 5 class names, compiled by your application, like the storefront. The JS module ships prebuilt in `dist/`, so you need no Node toolchain. See [Front-End Setup](Installation-Front-End).

## PayPal account

- A PayPal developer account, for sandbox apps and test buyers.
- A PayPal Business account for live payments.
- For card fields, Apple Pay, and Google Pay, the live account must be approved for **advanced credit and debit card payments** and the wallet features. See [PayPal App and Credentials](Installation-Paypal-App).

## Hosting

- **HTTPS in production.** Live webhooks must use HTTPS (`ecommerce-paypal:webhooks:register` refuses an `http` URL in live mode), and Apple Pay only works over HTTPS.
- **A public URL** for PayPal's webhooks. During local development use a tunnel.
- **A queue worker.** Webhook side effects run as queued jobs on the engine's webhook queue (`artisanpack.ecommerce.webhooks.connection` / `.queue`).
- **The scheduler.** `ecommerce-paypal:authorizations:reauthorize` is scheduled daily. Run `php artisan schedule:work` (or a cron entry for `schedule:run`) if you use the `authorize` intent.
