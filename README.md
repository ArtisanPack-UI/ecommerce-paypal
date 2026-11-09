# ArtisanPack UI Ecommerce PayPal

The PayPal gateway for the [ArtisanPack UI ecommerce engine](https://github.com/ArtisanPack-UI/ecommerce): PayPal, Pay Later, and Venmo buttons, advanced card fields with 3-D Secure, Apple Pay, and Google Pay through PayPal's Orders v2 API, with signature-verified webhooks and vaulted payment methods. Card data is collected by PayPal's SDK only and never reaches your server (SAQ-A).

> **Status:** in development toward v1.0.0. The build plan is in [`plans/17-ecommerce-paypal-spec.md`](plans/17-ecommerce-paypal-spec.md) and the work is tracked in the [v1.0 milestone](https://github.com/ArtisanPack-UI/ecommerce-paypal/milestone/1).

## Requirements

- PHP 8.3+ with `ext-openssl`
- Laravel 12 or 13
- `artisanpack-ui/ecommerce` 1.2+

## Installation

```bash
composer require artisanpack-ui/ecommerce-paypal
php artisan migrate
```

The service provider is auto-discovered and registers the package with the engine's satellite registry.

## Contributing

As an open source project, this package is open to contributions from anyone. Please [read through the contributing
guidelines](CONTRIBUTING.md) to learn more about how you can contribute to this project.

## License

MIT. See [LICENSE](LICENSE).
