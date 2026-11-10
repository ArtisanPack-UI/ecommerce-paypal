---
title: Front-End Setup
---

# Front-End Setup

The package ships no CSS build and no JavaScript you need to build. The Livewire driver views use Tailwind CSS 4 and daisyUI 5 classes that your application compiles, and all PayPal SDK logic lives in one prebuilt ES module, `paypal-checkout.js`.

## 1. Add the views to your Tailwind sources

`php artisan ecommerce-paypal:install` prints the line. Add it to your Tailwind entry (for example `resources/css/app.css`), next to the storefront's and the component library's sources:

```css
@source "../../vendor/artisanpack-ui/ecommerce-paypal/resources/views/**/*.blade.php";
```

Until you do, the payment UI and account page render unstyled. The setup is otherwise the storefront's: see the storefront's Front-End Setup guide for Tailwind 4 and daisyUI 5.

## 2. Publish the JS module

The install command publishes `dist/paypal-checkout.js` to `public/vendor/ecommerce-paypal/paypal-checkout.js`. Republish it after every upgrade:

```bash
php artisan vendor:publish --tag=ecommerce-paypal-assets --force
```

The Livewire drivers and the Pay Later components import it with a version query string (`?v=1.0.0`), so browsers pick up a new release. Add the command to your deploy script, or to `post-update-cmd` in your `composer.json`:

```json
"post-update-cmd": [
    "@php artisan vendor:publish --tag=ecommerce-paypal-assets --force"
]
```

The module loads PayPal's JS SDK (v5) itself, once per page, so don't add PayPal's `<script>` tag to your layout. Apple Pay and Google Pay load Apple's and Google's scripts the same way, only when those methods render.

React and Vue storefronts can import the module from `public/vendor/ecommerce-paypal` or bundle `vendor/artisanpack-ui/ecommerce-paypal/dist/paypal-checkout.js`. See [JavaScript Module](Extending-Javascript-Module).

## 3. Allow PayPal in your Content-Security-Policy

If your site sends a Content-Security-Policy, add the sources the install command prints:

```text
script-src  https://www.paypal.com https://*.paypal.com https://applepay.cdn-apple.com https://pay.google.com
frame-src   https://*.paypal.com https://pay.google.com
connect-src https://*.paypal.com
img-src     https://*.paypalobjects.com
```

See [Security and CSP](Advanced-Security-Csp) for the details and a `spatie/laravel-csp` example.

## 4. Layout requirements

The drivers run inside the storefront's checkout, so the storefront's layout contract applies: Livewire's assets, your Tailwind build, and `@stack( 'scripts' )`. The driver scripts use Livewire's `@script`, and the Pay Later Blade component prints an inline `<script type="module">`, so a CSP with `script-src` nonces must cover inline scripts the way your Livewire setup already does.

## Card field styling

PayPal's card fields are iframes, so your CSS can't reach inside them. The module reads your daisyUI theme's `--color-base-content` and `--color-error` and the page font, and passes them to PayPal as the field style. Borders and backgrounds sit on the containers, which your Tailwind build styles. To pass your own style, see `cardFieldStyle()` and the `style` callback on [JavaScript Module](Extending-Javascript-Module).
