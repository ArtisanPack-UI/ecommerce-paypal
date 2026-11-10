---
title: Browser Tests
---

# Browser Tests

For contributors. The browser suite (Pest 4's browser plugin on Playwright) runs the package's pages in Chromium. It covers what Livewire tests can't: the real `paypal-checkout.js` module and PayPal SDK, keyboard use and focus, live region announcements, and an axe accessibility check.

## Running it

```bash
npm ci
npx playwright install chromium
composer test:browser            # no credentials needed
composer test:browser:sandbox    # the PayPal sandbox journeys
```

Both commands build the workbench stylesheet first (`npm run build:browser`). `composer test` runs only the Unit and Feature suites, because loading the Browser suite starts Playwright.

## The two halves

| Group | Tests | Needs | Runs in CI |
| --- | --- | --- | --- |
| `browser` (without `sandbox`) | `SavedMethodsAccountTest`, `PaymentDriversTest` | Nothing. PayPal's API is answered by `PayPalFake`, and the SDK is asked for with a client id PayPal doesn't know, so it fails to load. | Every push and pull request (`ci.yml`, `browser` job) |
| `sandbox` | `Sandbox/CheckoutJourneysTest` | Sandbox credentials (below) | Nightly and on release branches (`sandbox.yml`) |

`SavedMethodsAccountTest` checks the account page with axe, removes a method and makes one the default with the keyboard alone, and checks that focus lands on the list heading after a removal. `PaymentDriversTest` checks every driver's labels and its failed-load alert, and runs axe at the payment step.

`CheckoutJourneysTest` covers the following:

- **Buttons:** they render from the real SDK; cancelling in PayPal returns to the payment step with a message; a sandbox buyer approves and the order completes.
- **Card fields:** validation errors; a frictionless 3-D Secure card; a challenge card; a card whose liability shift fails, including where focus goes afterwards.
- **Saved cards:** a card saved at checkout, used at the next checkout, then removed from the account page (and at PayPal).

| Variable | Used by |
| --- | --- |
| `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_CLIENT_SECRET` | Every journey (all skip without them) |
| `PAYPAL_SANDBOX_BUYER_EMAIL`, `PAYPAL_SANDBOX_BUYER_PASSWORD` | Wallet approval (a sandbox personal account) |
| `PAYPAL_SANDBOX_CARD_NUMBER` | Saved-card journey (default `4111111111111111`) |
| `PAYPAL_SANDBOX_3DS_FRICTIONLESS_CARD`, `PAYPAL_SANDBOX_3DS_CHALLENGE_CARD`, `PAYPAL_SANDBOX_3DS_FAILED_CARD`, `PAYPAL_SANDBOX_3DS_CODE` | The 3-D Secure journeys. Take the cards and code from PayPal's 3-D Secure test card table. |

A journey that needs a variable that isn't set is skipped and names it.

## How the harness works

| Piece | Where | Role |
| --- | --- | --- |
| Base test case | `tests/BrowserTestCase.php` | Serves the Testbench app from `tests/Browser/workbench/public`, loads the workbench stylesheet through `ap.ecommerceStorefrontLivewire.layout.viteEntries`, and copies `dist/paypal-checkout.js` to where `ecommerce-paypal:install` would publish it |
| Workbench | `tests/Browser/workbench/resources/css/app.css` | Stands in for a host's Tailwind entry: this package's, the storefront's, and the component library's views as `@source`s, plus daisyUI |
| Build | `vite.browser.config.js` | `npm run build:browser` writes the stylesheet to `tests/Browser/workbench/public/build` (git-ignored) |
| Checkout set-up | `tests/Browser/Support/Checkout.php` | A signed-in shopper's cart with contact, address, and shipping chosen, so the checkout opens at "Review order" |
| PayPal's pages | `tests/Browser/Support/PayPalSandboxPages.php` | Every selector for a page PayPal hosts: login, review, cancel, card field iframes, and the 3-D Secure challenge |
| Accessibility | `tests/Browser/Support/Accessibility.php` | `Accessibility::assertAccessible( $page )` runs every axe rule with `target-size` on, except failures listed with their component-library issue |

**Popups.** Pest's browser plugin can't follow a popup window, so the journeys approve and cancel on PayPal's full-page flow (`https://www.sandbox.paypal.com/checkoutnow?token={order}`). That's the same flow the SDK falls back to when popups are blocked. PayPal then sends the browser to the Order's return or cancel URL, which is the storefront's checkout return route.

**When PayPal changes its pages,** only `PayPalSandboxPages` should need updating.

## Failures in CI

The browser plugin saves a screenshot of the page when a test fails, in `tests/Browser/Screenshots` (git-ignored). Both CI workflows upload that folder as a build artifact when a browser job fails.
