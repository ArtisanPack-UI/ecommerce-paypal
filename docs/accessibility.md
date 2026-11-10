---
title: Accessibility
---

# Accessibility

The payment drivers, the Pay Later message, and the saved payment methods page target **WCAG 2.2 AA**. PayPal's buttons, card fields, and wallet sheets are rendered by PayPal, Apple, and Google inside iframes or native sheets; the package can't change their markup, but it labels, announces, and manages focus around them.

## What is in place

- **Labeled groups.** The PayPal buttons sit in a group named "Pay with PayPal", and the Apple Pay and Google Pay buttons in "Pay with Apple Pay" / "Pay with Google Pay".
- **Card fields.** Each hosted field has a visible label, is a `group` named by that label, and has its own inline error linked with `aria-describedby` and announced politely. PayPal's iframes take their `title` from the visible label, so they are named in the shopper's language. Errors appear as the shopper leaves a field and clear while they correct it. On a failed submit, the error names the fields to check ("Check these card details: Card number and Expiration date."), so it doesn't rely on color, and focus moves to the first invalid field.
- **Live regions.** Each driver has a polite status region ("Loading PayPal…", confirming, canceled, not available) and an assertive error region (declines, 3-D Secure refusals, load failures). Messages are cleared before being set again, so a repeated message is announced again. When a method isn't available to the shopper, that is announced once, politely, and focus isn't moved.
- **Focus after cancel or failure.** Closing PayPal or a wallet sheet returns focus to the payment step's heading (or, when paying for an order already placed, such as after a declined funding source, to the placement heading). After a failed payment, focus moves only if it was lost (left on the page body when PayPal's window or the 3-D Secure modal closed): to the heading for PayPal and the wallets, and back to the button the shopper pressed for card payments. A failure that arrives while the shopper is on another control leaves their focus there. The error is announced in either case.
- **No color alone.** Errors and the decline-restart notice are written out; soft alerts use the base text color so their text keeps 4.5:1 contrast, and inline errors mix the error color with the text color for the same reason.
- **Disabled while busy.** "Pay now", "Pay with this card", and the "save" checkbox are disabled while PayPal works, so a second press can't start a second payment. They are enabled again when the checkout keeps the shopper on the payment step (the order couldn't be placed, or the request failed).
- **Saved payment methods.** The list is a labeled list with a heading per method. "Make default" and "Remove" name the method for screen readers ("Remove: Visa ending in 4242"). Removal asks in a modal dialog, and after a removal focus moves to the list heading. "Default" and "Expired" are written as badges, not shown by color.
- **New tabs.** In the admin order panel, links to PayPal say they open a new tab.
- **Pay Later messages.** PayPal's own accessible markup is kept. The box reserves a line's height so content below doesn't jump while it loads.
- **Translated.** Every label, message, and announcement goes through Laravel's translator, and the SDK is loaded in the shopper's locale. See [Localization](Localization).

## Inside PayPal's frames

The contents of PayPal's buttons, card fields, and 3-D Secure modal, and Apple's and Google's payment sheets, are the providers' responsibility. Report problems with them to PayPal, Apple, or Google. If you find a problem in what the package renders around them, open an issue on the [GitHub repository](https://github.com/ArtisanPack-UI/ecommerce-paypal/issues).

## How it is tested

- Feature tests cover the labels, live regions, and focus hooks in the rendered driver and account views.
- The browser suite runs axe (every rule, plus WCAG 2.2 `target-size`) on each driver and on the account page, and removes and re-defaults a saved method by pressing Enter on its focused buttons, checking where focus lands. (Tab order itself is not asserted; it is covered in the manual keyboard pass before each release.) It runs on every pull request without PayPal credentials.
- A sandbox half of the suite drives the real PayPal sandbox: canceling and approving in PayPal, card validation errors, 3-D Secure, and where focus goes after a refused card. It runs on demand with sandbox credentials, locally or through the `sandbox.yml` workflow, and is skipped without them. See [Browser Tests](Advanced-Browser-Tests).
- Screen reader passes (VoiceOver with Safari, NVDA with Firefox) are done by hand before each release, on the cancel, error, and 3-D Secure return paths.

## Building your own wrapper

React and Vue wrappers around the [JavaScript module](Extending-Javascript-Module) should do the same: put the PayPal container in a labeled group, announce status and errors in live regions, return focus to the step heading after a cancel, and disable your submit button while `submit()` runs.
