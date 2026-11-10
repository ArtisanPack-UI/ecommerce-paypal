---
title: JavaScript Module (React and Vue)
---

# JavaScript Module (React and Vue)

All PayPal SDK logic lives in one framework-agnostic ES module, `paypal-checkout.js`. The Livewire drivers in this package use it, and React and Vue storefronts wrap the same functions, so PayPal's client quirks are handled in one place.

- Source: `resources/js/paypal-checkout.js`
- Build: `dist/paypal-checkout.js` (committed; no Node toolchain needed)
- Published to: `public/vendor/ecommerce-paypal/paypal-checkout.js` (tag `ecommerce-paypal-assets`)

It loads PayPal's JS SDK v5 (`https://www.paypal.com/sdk/js`). The PayPal Order is always created on the server, so `createOrder` only hands back its id. Card data is entered in PayPal-hosted fields and never passes through the module.

## Getting the client config

Every function takes the **client config** a PayPal gateway returns from `clientConfig()`. Over the engine's REST API, it is `checkout.payment.client` in the response to `POST checkout/{cart}/session`:

```json
{
    "driver": "paypal-buttons",
    "flow": "embedded",
    "publishable_key": "AXy…client-id",
    "client_secret": "5O190127TN364715T",
    "redirect_url": null,
    "gateway": "paypal",
    "options": {
        "order_id": "5O190127TN364715T",
        "amount": "54.00",
        "currency": "USD",
        "intent": "capture",
        "mode": "sandbox",
        "locale": "en_US",
        "components": [ "buttons", "messages" ],
        "enable_funding": [ "venmo", "paylater" ],
        "disable_funding": [],
        "restart": false,
        "button": { "layout": "vertical", "color": "gold", "shape": "rect", "label": "paypal" },
        "pay_later": true,
        "venmo": true,
        "vault": { "available": false, "save": false }
    }
}
```

`driver` tells you which function to call:

| `driver` | Gateway | Function |
| --- | --- | --- |
| `paypal-buttons` | `paypal` | `mountButtons()` |
| `paypal-card-fields` | `paypal-card` | `mountCardFields()` |
| `paypal-apple-pay` | `paypal-apple-pay` | `mountApplePay()` |
| `paypal-google-pay` | `paypal-google-pay` | `mountGooglePay()` |

`publishable_key` is the PayPal client id. `options.order_id` (and `client_secret`) is the PayPal Order id. Card gateways add `three_d_secure` and `require_liability_shift`; vaulting gateways add `vault` and, for a returning buyer, `user_id_token`.

After the buyer approves, call `POST checkout/{cart}/finalize` with the session reference, as for any gateway.

## Importing it

```js
const paypal = await import( '/vendor/ecommerce-paypal/paypal-checkout.js' );
```

Or bundle it from the Composer package:

```js
import { mountButtons } from '../../vendor/artisanpack-ui/ecommerce-paypal/dist/paypal-checkout.js';
```

Load it from one URL per page: SDK loads are shared through module state, so two copies would load the SDK twice.

## Exported functions

### `mountButtons( container, config, callbacks )`

Renders PayPal's Smart Buttons (PayPal, Pay Later, Venmo) in `container`, styled with `options.button`.

| Callback | Called with | Notes |
| --- | --- | --- |
| `onApprove` | PayPal's approval data (`orderID`, …) | May return a promise. Resolve it with `{ restart: true }` when finalize answered `instrument_declined`: the buttons call PayPal's `actions.restart()` so the buyer picks another funding source on the same Order |
| `onCancel` | PayPal's data | The buyer closed PayPal |
| `onError` | The error | |
| `onIneligible` | — | PayPal can't be offered to this buyer; nothing is rendered |
| `onClick` | PayPal's data | |

Resolves with `{ eligible, close() }`.

If your wrapper resolves `onApprove` before finalizing instead, follow the Livewire driver: when finalize fails with `instrument_declined`, fetch the client config again; `options.restart` is then `true`, and re-mounting the buttons reopens the same Order.

### `mountCardFields( fields, config, callbacks )`

Renders PayPal's hosted card fields. `fields` maps `number`, `expiry`, `cvv`, and optionally `name` to container elements.

| Callback | Notes |
| --- | --- |
| `onApprove` | The card payment was approved (after 3-D Secure) |
| `onError` | |
| `onIneligible` | Card fields aren't available for this merchant or buyer |
| `onChange` | PayPal's field state on every change |
| `onBlur` | Field state when a field loses focus (falls back to `onChange`) |
| `style` | A `CardFields` style object. Defaults to `cardFieldStyle()` of the first container |

Resolves with `{ eligible, submit( options ), getState(), close() }`. Call `submit()` from your "Pay now" button; it runs 3-D Secure in PayPal's modal. Use `getState()` to find invalid fields before submitting.

### `mountApplePay( container, config, callbacks )`

Renders an Apple Pay button when `ApplePaySession.canMakePayments()` is true and PayPal reports the merchant eligible; otherwise calls `onIneligible`. Loads Apple's SDK (`APPLE_PAY_SDK_URL`). Callbacks: `onApprove` (with `{ orderID }`, after PayPal confirmed the Order), `onCancel`, `onError`, `onIneligible`, and `label` (the total's label on the payment sheet; use the store name). Resolves with `{ eligible, close() }`.

### `mountGooglePay( container, config, callbacks )`

Renders a Google Pay button when Google's `isReadyToPay` passes; otherwise calls `onIneligible`. Loads Google's `pay.js` (`GOOGLE_PAY_SDK_URL`), runs in Google's `TEST` environment in sandbox mode and `PRODUCTION` in live, and runs PayPal's `initiatePayerAction` for 3-D Secure when PayPal asks. Callbacks: `onApprove` (with `{ orderID }`), `onCancel`, `onError`, `onIneligible`. Resolves with `{ eligible, close() }`.

### `mountMessages( container, config, settings )`

Renders a Pay Later message. `settings`: `amount` (as PayPal writes it, `'240.00'`; defaults to `options.amount`), `placement` (`product`, `cart`, or `payment`), `style` (defaults to `{ layout: 'text' }`), and `onRender`. Resolves with `{ update( amount ) }` to re-render for a new total. PayPal renders nothing for an ineligible buyer or amount.

Outside checkout there is no session, so get the config from your server: build it with `ArtisanPackUI\EcommercePayPal\Support\PayLaterMessaging::settings( $placement, $money )` (which returns `config`, `amount`, and `placement`) after checking `PayLaterMessaging::enabledFor( $placement, $money )`.

### Lower-level helpers

| Export | Does |
| --- | --- |
| `loadSdk( config, { components } )` | Loads the SDK once and resolves with its namespace. Calls with the same client id, currency, intent, locale, funding, `user_id_token`, and `partner_attribution_id` share one script; when a later call needs more components, a second script loads under its own `data-namespace` |
| `resetSdk()` | Forgets every load (tests, hot module reloading) |
| `loadScript( src )` | Loads a third-party script once per page, with the page's CSP nonce |
| `cspNonce( override )` | The CSP nonce scripts get: `override`, else the nonce of a script already on the page, else empty |
| `sdkParams( config, components )` / `sdkUrl( config, components )` | The SDK query string / URL for a config |
| `orderIdOf( config )` | The PayPal Order id (`options.order_id`, else `client_secret`) |
| `cardFieldStyle( element )` | A card field style from the page's daisyUI tokens (`--color-base-content`, `--color-error`) and font |
| `SDK_URL`, `APPLE_PAY_SDK_URL`, `GOOGLE_PAY_SDK_URL` | Script URLs |

The SDK query string carries `client-id`, `components`, `currency`, `intent`, `commit=true`, and, when set, `locale`, `enable-funding`, `disable-funding`, and `buyer-country` (sandbox only, from `options.buyer_country`). `options.user_id_token` and `options.partner_attribution_id` become `data-user-id-token` and `data-partner-attribution-id`. Add the last three with the `ap.ecommerce.paypal.clientOptions` filter if you need them. The page's CSP nonce (or `options.csp_nonce`) becomes `nonce` and `data-csp-nonce`; see [Security and CSP](Advanced-Security-Csp#nonces).

## React example

```jsx
import { useEffect, useRef } from 'react';

export function PayPalButtons( { client, onApproved, onFailed } ) {
    const ref = useRef( null );

    useEffect( () => {
        let mounted  = null;
        let disposed = false;

        import( '/vendor/ecommerce-paypal/paypal-checkout.js' )
            .then( ( paypal ) => paypal.mountButtons( ref.current, client, {
                onApprove: ( data ) => onApproved( data.orderID ),
                onCancel: () => {},
                onError: () => onFailed(),
                onIneligible: () => onFailed( 'ineligible' ),
            } ) )
            .then( ( handle ) => {
                // The component may have unmounted while PayPal loaded.
                if ( disposed ) {
                    handle?.close();

                    return;
                }

                mounted = handle;
            } )
            // The SDK couldn't load (offline, blocked by CSP, …).
            .catch( () => disposed || onFailed( 'load_failed' ) );

        return () => {
            disposed = true;
            mounted?.close();
        };
    }, [ client.options.order_id ] );

    return <div ref={ ref } role="group" aria-label="Pay with PayPal" />;
}
```

## Vue example

```vue
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps( { client: Object } );
const emit  = defineEmits( [ 'approved', 'failed' ] );
const el     = ref( null );
let mounted  = null;
let disposed = false;

onMounted( async () => {
    let handle = null;

    try {
        const paypal = await import( '/vendor/ecommerce-paypal/paypal-checkout.js' );

        handle = await paypal.mountButtons( el.value, props.client, {
            onApprove: ( data ) => emit( 'approved', data.orderID ),
            onError: () => emit( 'failed' ),
            onIneligible: () => emit( 'failed', 'ineligible' ),
        } );
    } catch {
        // The SDK couldn't load (offline, blocked by CSP, …).
        if ( ! disposed ) {
            emit( 'failed', 'load_failed' );
        }

        return;
    }

    // The component may have unmounted while PayPal loaded.
    if ( disposed ) {
        handle?.close();

        return;
    }

    mounted = handle;
} );

onBeforeUnmount( () => {
    disposed = true;
    mounted?.close();
} );
</script>

<template>
    <div ref="el" role="group" aria-label="Pay with PayPal" />
</template>
```

Wrappers should match the Livewire drivers' accessibility: a labelled group, polite and assertive live regions for status and errors, and focus back to the payment step heading after a cancel. See [Accessibility](Accessibility).

## Saved methods in React and Vue

Shoppers manage saved methods through the [REST endpoints](Vaulting#rest-endpoints), and a returning PayPal buyer's `user_id_token` comes in the client config. Saving during checkout and paying with a saved card go through the session call's `payment_options` (see [From API storefronts](Vaulting#from-api-storefronts)):

```jsx
// `api( method, path, body )` is your storefront's fetch wrapper, which adds
// the shopper's Sanctum token and an Idempotency-Key.
async function startPayment( cart, { saveCard, savedCardId } ) {
    const options = savedCardId
        ? { saved_payment_method: savedCardId }
        : { save_payment_method: saveCard };

    const { checkout } = await api( 'POST', `/checkout/${ cart }/session`, {
        return_url: `${ location.origin }/checkout/return`,
        payment_options: options,
    } );

    return checkout.payment;
}

export function CardStep( { cart, savedCards, onPlaced, onFailed } ) {
    const [ choice, setChoice ] = useState( savedCards[ 0 ]?.id ?? 'new' );
    const [ saveCard, setSaveCard ] = useState( false );

    const payWithSavedCard = async () => {
        try {
            const payment = await startPayment( cart, { savedCardId: choice } );
            // No card fields: the Order pays with the saved card's vault id.
            onPlaced( await api( 'POST', `/checkout/${ cart }/finalize`, { payment_reference: payment.reference } ) );
        } catch ( error ) {
            // 422 saved-payment-method-unavailable / -invalid: pick another card.
            onFailed( error );
        }
    };

    // For a new card, create the session with the "save" choice (again
    // whenever it changes), then mount the card fields with
    // `payment.client` as in the examples above.
    // …
}
```

Fetch `savedCards` from `GET me/paypal/payment-methods` and keep only `method: 'card'`. Send `payment_options` again whenever the shopper ticks or unticks "save" or picks another card: the server keeps the PayPal Order while the options match and replaces it when they change, so always mount with the latest `payment.client`.

## Building the module

Contributors change `resources/js/paypal-checkout.js`, then:

```bash
npm ci
npm run build   # writes dist/paypal-checkout.js
npm test        # Vitest
```

Commit `dist/`. CI fails when it doesn't match the source.
