---
title: Localization
---

# Localization

Every user-facing string in the package (driver messages, the account page, the admin panel, order notes, settings labels, and command output) goes through Laravel's translation functions. The package ships JSON catalogues for four languages, and loads PayPal's SDK in a matching locale.

## Shipped languages

| Locale | File |
| --- | --- |
| English | `lang/en.json` |
| Spanish | `lang/es.json` |
| French | `lang/fr.json` |
| German | `lang/de.json` |

The catalogues are loaded with `loadJsonTranslationsFrom()`, so strings are keyed by their English text and follow the application locale (`app()->getLocale()`).

## Overriding strings

Publish the catalogues:

```bash
php artisan vendor:publish --tag=ecommerce-paypal-lang
```

They are copied to `lang/vendor/ecommerce-paypal/`. The package loads that directory as well as its own, so you can change individual strings there.

Every package's JSON catalogue loads into one shared namespace. A key the package shares with the engine or the storefront (for example "Default" or "Cancel") must read the same in each, or which one wins depends on load order. When you override a shared string, override it the same way for every package that uses it.

## PayPal's locale

PayPal renders its buttons, card fields, and checkout pages in its own languages. The package maps the app locale to one PayPal supports, for both the JS SDK (`locale`, for example `fr_CA`) and the Order's `experience_context.locale` (for example `fr-CA`):

| App locale | PayPal locale |
| --- | --- |
| `fr_CA`, `fr-CA` | `fr-CA` (a supported language and region is used as is) |
| `de` | `de-DE` (a bare language gets its default region) |
| `pt` | `pt-BR` |
| `nb` | `no-NO` |
| `zh-Hant-TW` | `zh-TW` (script subtags are skipped) |
| `xx`, empty | `en-US` (the fallback) |

The Order uses the cart's `locale` when it has one, otherwise the app locale. A locale outside PayPal's list falls back to the language's default region, then to `en-US`.

## Statement descriptor and brand name

`brand_name` and `soft_descriptor` are shown by PayPal and the card issuer as you set them; they aren't translated.

## Translation lint

CI fails when a user-facing string in `src/` or `resources/` isn't wrapped in `__()` / `trans_choice()`, or is missing from any of `lang/{en,es,fr,de}.json` (the engine's `ecommerce:lint:translations`). A unit test (`tests/Unit/TranslationCataloguesTest.php`) also checks that the four catalogues have the same keys, that no translation is blank, and that each keeps its original's `:placeholders` and plural forms. Add new strings to all four.
