# ArtisanPack UI Ecommerce PayPal

The PayPal gateway for the [ArtisanPack UI ecommerce engine](https://github.com/ArtisanPack-UI/ecommerce): PayPal, Pay Later, and Venmo buttons, advanced card fields with 3-D Secure, Apple Pay, and Google Pay through PayPal's Orders v2 API, with signature-verified webhooks and saved payment methods. Card data is collected by PayPal's SDK only and never reaches your server (SAQ-A).

It registers four gateways with the engine (`paypal`, `paypal-card`, `paypal-apple-pay`, `paypal-google-pay`). With [`ecommerce-storefront-livewire`](https://github.com/ArtisanPack-UI/ecommerce-storefront-livewire) installed it also ships the Livewire payment UI and a saved payment methods account page; React and Vue storefronts use its framework-agnostic JS module.

## Features

- **Four checkout choices**: PayPal (with Pay Later and Venmo), credit or debit card, Apple Pay, and Google Pay, each switched on separately.
- **Capture or authorize**: Idempotent captures, voids, and full or partial refunds. Uncaptured authorizations are reauthorized after PayPal's honor period by a scheduled command.
- **3-D Secure**: Card and wallet payments without a liability shift can be refused.
- **Verified webhooks**: Local signature verification (or PayPal postback), with pending captures, dashboard refunds, reversals, disputes, and vault changes recorded on the order.
- **Saved payment methods**: PayPal and cards, managed on an account page or through REST endpoints. Only PayPal's token ids and display data are stored.
- **Operations**: Setup commands, an admin order panel, and PayPal debug ids in every error log, with credentials and personal data redacted.
- **Extension points**: Filters for the Order payload, client options, and currencies; actions for captures, denials, expiring authorizations, disputes, and every webhook event.
- **Accessible and translated**: Targets WCAG 2.2 AA. Ships English, Spanish, French, and German.

## Requirements

- PHP 8.3+ with `ext-openssl`
- Laravel 12 or 13
- `artisanpack-ui/ecommerce` 1.3 or later 1.x (`^1.3`)
- Optional: `artisanpack-ui/ecommerce-storefront-livewire` (payment UI, account page) and `artisanpack-ui/ecommerce-admin-livewire` (order panel, settings screen)

## Quick start

```bash
composer require artisanpack-ui/ecommerce-paypal
php artisan ecommerce-paypal:install --migrate
```

The service provider is auto-discovered and registers the package with the engine's satellite registry. The install command publishes the config and the JS module, runs the migrations, and prints the Tailwind `@source` line and the Content-Security-Policy sources.

Add your PayPal REST app's credentials to `.env`:

```dotenv
PAYPAL_MODE=sandbox
PAYPAL_CLIENT_ID=your-client-id
PAYPAL_CLIENT_SECRET=your-secret
```

Register the webhook and check the setup:

```bash
php artisan ecommerce-paypal:webhooks:register
php artisan ecommerce-paypal:verify-credentials
```

Then set the webhook id the first command printed, and turn PayPal on:

```dotenv
PAYPAL_WEBHOOK_ID=the-printed-id
PAYPAL_ENABLED=true
```

PayPal now appears at checkout. Turn on cards, Apple Pay, Google Pay, Pay Later messages, and saved payment methods in the config or the store settings.

## Documentation

- [Documentation home](docs/home.md)
- [Getting Started](docs/getting-started.md)
- [Installation](docs/installation.md): [requirements](docs/installation/requirements.md), [PayPal app and credentials](docs/installation/paypal-app.md), [configuration](docs/installation/configuration.md), [front-end setup](docs/installation/front-end.md)
- [Payment Methods](docs/payment-methods.md): [PayPal buttons](docs/payment-methods/paypal-buttons.md), [card fields](docs/payment-methods/card-fields.md), [Apple Pay](docs/payment-methods/apple-pay.md), [Google Pay](docs/payment-methods/google-pay.md), [Pay Later messaging](docs/payment-methods/pay-later-messaging.md)
- [Saved Payment Methods](docs/vaulting.md), [Webhooks](docs/webhooks.md)
- [Extending](docs/extending.md): [hooks reference](docs/extending/hooks-reference.md), [availability](docs/extending/availability.md), [JavaScript module (React and Vue)](docs/extending/javascript-module.md)
- [Accessibility](docs/accessibility.md), [Localization](docs/localization.md)
- [Advanced](docs/advanced.md): [Artisan commands](docs/advanced/artisan-commands.md), [security and CSP](docs/advanced/security-csp.md), [troubleshooting](docs/advanced/troubleshooting.md), [admin order panel](docs/advanced/admin-order-panel.md), [testing your application](docs/advanced/testing.md), [upgrade guide](docs/advanced/upgrade-guide.md), [sandbox integration tests](docs/advanced/sandbox-tests.md), [browser tests](docs/advanced/browser-tests.md)

## Testing

```bash
composer test   # Unit and Feature suites
npm test        # the JS module's Vitest suite (run npm ci first)
composer lint
```

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing
guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.

## License

MIT. See [LICENSE](LICENSE).
