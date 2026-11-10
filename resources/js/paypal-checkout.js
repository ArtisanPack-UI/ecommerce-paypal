/**
 * PayPal checkout module for ArtisanPack UI storefronts.
 *
 * Framework-agnostic: the Livewire drivers in this package and the React and
 * Vue storefront wrappers all mount PayPal through these functions (spec D5,
 * §9.1). Each takes the client config a PayPal gateway returns from
 * `clientConfig()`:
 *
 *     { driver, flow, publishable_key, client_secret, options: { order_id,
 *       amount, currency, intent, mode, locale, components, enable_funding,
 *       disable_funding, button?, user_id_token?, csp_nonce?, ... } }
 *
 * Scripts it adds carry the page's Content-Security-Policy nonce: `csp_nonce`
 * when the host passes one, otherwise the nonce of a script already on the
 * page (see `cspNonce()`).
 *
 * The PayPal JS SDK (v5, `https://www.paypal.com/sdk/js`) is loaded once per
 * page for a given client id, currency, and intent. The Order is always
 * created server-side, so `createOrder` only hands back its id. Card data is
 * entered in PayPal-hosted fields and never passes through this module.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */

/**
 * The PayPal JS SDK v5 URL (sandbox and live share it; the client id picks
 * the environment).
 *
 * @since 1.0.0
 *
 * @type {string}
 */
export const SDK_URL = 'https://www.paypal.com/sdk/js';

/**
 * Apple's Apple Pay JS SDK (for the `<apple-pay-button>` element).
 *
 * @since 1.0.0
 *
 * @type {string}
 */
export const APPLE_PAY_SDK_URL = 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';

/**
 * Google's Google Pay API.
 *
 * @since 1.0.0
 *
 * @type {string}
 */
export const GOOGLE_PAY_SDK_URL = 'https://pay.google.com/gp/p/js/pay.js';

/**
 * SDK loads, keyed by everything but the components.
 *
 * @type {Map<string, { components: Set<string>, namespace: string, promise: Promise<object> }>}
 */
const sdkLoads = new Map();

/**
 * Other scripts (Apple Pay, Google Pay), keyed by URL.
 *
 * @type {Map<string, Promise<void>>}
 */
const scriptLoads = new Map();

/**
 * How many extra SDK namespaces were created.
 *
 * @type {number}
 */
let extraNamespaces = 0;

/**
 * The options object of a client config.
 *
 * @param {object} config Client config.
 *
 * @returns {object}
 */
function optionsOf( config ) {
    return ( config && 'object' === typeof config.options && null !== config.options ) ? config.options : {};
}

/**
 * A list option as an array of non-empty strings.
 *
 * @param {unknown} value Option value.
 *
 * @returns {string[]}
 */
function list( value ) {
    const values = Array.isArray( value ) ? value : ( 'string' === typeof value ? value.split( ',' ) : [] );

    return values.map( ( item ) => String( item ).trim() ).filter( ( item ) => '' !== item );
}

/**
 * The PayPal Order id the SDK should use.
 *
 * @since 1.0.0
 *
 * @param {object} config Client config.
 *
 * @returns {string}
 */
export function orderIdOf( config ) {
    return String( optionsOf( config ).order_id || config?.client_secret || '' );
}

/**
 * The SDK query parameters for `config`, with `components` merged in.
 *
 * @since 1.0.0
 *
 * @param {object}   config     Client config.
 * @param {string[]} components Components to load (merged with the config's).
 *
 * @returns {URLSearchParams}
 */
export function sdkParams( config, components = [] ) {
    const options = optionsOf( config );
    const params  = new URLSearchParams();
    const wanted  = [ ...new Set( [ ...list( options.components ), ...list( components ) ] ) ].sort();

    params.set( 'client-id', String( config?.publishable_key || '' ) );
    params.set( 'components', ( wanted.length ? wanted : [ 'buttons' ] ).join( ',' ) );
    params.set( 'currency', String( options.currency || 'USD' ).toUpperCase() );
    params.set( 'intent', 'authorize' === options.intent ? 'authorize' : 'capture' );
    params.set( 'commit', 'true' );

    if ( options.locale ) {
        params.set( 'locale', String( options.locale ) );
    }

    if ( list( options.enable_funding ).length ) {
        params.set( 'enable-funding', list( options.enable_funding ).join( ',' ) );
    }

    if ( list( options.disable_funding ).length ) {
        params.set( 'disable-funding', list( options.disable_funding ).join( ',' ) );
    }

    // PayPal only honors buyer-country in the sandbox.
    if ( 'sandbox' === options.mode && options.buyer_country ) {
        params.set( 'buyer-country', String( options.buyer_country ).toUpperCase() );
    }

    return params;
}

/**
 * The SDK URL for `config`.
 *
 * @since 1.0.0
 *
 * @param {object}   config     Client config.
 * @param {string[]} components Extra components.
 *
 * @returns {string}
 */
export function sdkUrl( config, components = [] ) {
    return `${ SDK_URL }?${ sdkParams( config, components ).toString() }`;
}

/**
 * The key loads are shared under: every parameter but the components, plus
 * the script attributes.
 *
 * @param {object} config Client config.
 *
 * @returns {string}
 */
function loadKey( config ) {
    const params  = sdkParams( config );
    const options = optionsOf( config );

    params.delete( 'components' );

    return [ params.toString(), options.user_id_token || '', options.partner_attribution_id || '' ].join( '|' );
}

/**
 * The Content-Security-Policy nonce for scripts this module adds: `override`
 * (a host's `csp_nonce` option) or the nonce of a script already on the
 * page, such as Livewire's or Vite's. Empty when the page uses none.
 *
 * @since 1.0.0
 *
 * @param {string} [override] Nonce to use.
 *
 * @returns {string}
 */
export function cspNonce( override = '' ) {
    if ( override ) {
        return String( override );
    }

    const nonced = document.querySelector( 'script[nonce]' );

    // Browsers hide the attribute's value once the page loads; the
    // property keeps it.
    return nonced ? String( nonced.nonce || nonced.getAttribute( 'nonce' ) || '' ) : '';
}

/**
 * Appends a script and resolves once it loaded. A failed load is removed so
 * a later call can try again. The script carries the page's CSP nonce, so a
 * nonce-based `script-src` allows it.
 *
 * @param {string}                 src        Script URL.
 * @param {Record<string, string>} attributes Extra attributes.
 *
 * @returns {Promise<void>}
 */
function appendScript( src, attributes = {} ) {
    return new Promise( ( resolve, reject ) => {
        const script = document.createElement( 'script' );
        const nonce  = cspNonce( attributes.nonce );

        script.src   = src;
        script.async = true;

        if ( nonce ) {
            script.nonce = nonce;
            script.setAttribute( 'nonce', nonce );
        }

        for ( const [ name, value ] of Object.entries( attributes ) ) {
            if ( 'nonce' === name ) {
                continue;
            }

            script.setAttribute( name, value );
        }

        script.addEventListener( 'load', () => resolve() );
        script.addEventListener( 'error', () => {
            script.remove();
            reject( new Error( `Could not load ${ src.split( '?' )[ 0 ] }` ) );
        } );

        document.head.appendChild( script );
    } );
}

/**
 * Loads a third-party script once per page.
 *
 * @since 1.0.0
 *
 * @param {string} src Script URL.
 *
 * @returns {Promise<void>}
 */
export function loadScript( src ) {
    if ( ! scriptLoads.has( src ) ) {
        scriptLoads.set( src, appendScript( src ).catch( ( error ) => {
            scriptLoads.delete( src );

            throw error;
        } ) );
    }

    return scriptLoads.get( src );
}

/**
 * Loads the PayPal JS SDK once and resolves with its namespace object.
 *
 * Calls that share a client id, currency, intent, locale, and funding
 * settings share one script. When a later call needs components the loaded
 * script lacks, a second script is loaded under its own `data-namespace`
 * with the merged components, and later calls reuse that one.
 *
 * @since 1.0.0
 *
 * @param {object}   config            Client config.
 * @param {object}   [settings]        Settings.
 * @param {string[]} [settings.components] Components needed beyond the config's.
 *
 * @returns {Promise<object>} The SDK (`window.paypal`, or the namespace it was loaded under).
 */
export function loadSdk( config, { components = [] } = {} ) {
    const key      = loadKey( config );
    const wanted   = new Set( sdkParams( config, components ).get( 'components' ).split( ',' ) );
    const existing = sdkLoads.get( key );

    if ( existing && [ ...wanted ].every( ( component ) => existing.components.has( component ) ) ) {
        return existing.promise;
    }

    const merged    = new Set( [ ...( existing ? existing.components : [] ), ...wanted ] );
    const namespace = ( existing || [ ...sdkLoads.values() ].length ) ? `paypal_ap_${ ++extraNamespaces }` : 'paypal';
    const options   = optionsOf( config );
    const attrs     = {};

    if ( 'paypal' !== namespace ) {
        attrs[ 'data-namespace' ] = namespace;
    }

    if ( options.user_id_token ) {
        attrs[ 'data-user-id-token' ] = String( options.user_id_token );
    }

    if ( options.partner_attribution_id ) {
        attrs[ 'data-partner-attribution-id' ] = String( options.partner_attribution_id );
    }

    // PayPal styles its buttons with this nonce under a nonce-based CSP.
    const nonce = cspNonce( options.csp_nonce );

    if ( nonce ) {
        attrs.nonce               = nonce;
        attrs[ 'data-csp-nonce' ] = nonce;
    }

    const entry = { components: merged, namespace, promise: null };

    entry.promise = appendScript( sdkUrl( config, [ ...merged ] ), attrs )
        .then( () => {
            const sdk = window[ namespace ];

            if ( ! sdk ) {
                throw new Error( 'The PayPal SDK did not load.' );
            }

            return sdk;
        } )
        .catch( ( error ) => {
            if ( sdkLoads.get( key ) === entry ) {
                if ( existing ) {
                    sdkLoads.set( key, existing );
                } else {
                    sdkLoads.delete( key );
                }
            }

            throw error;
        } );

    sdkLoads.set( key, entry );

    return entry.promise;
}

/**
 * Forgets every load (for tests and hot module reloads).
 *
 * @since 1.0.0
 *
 * @returns {void}
 */
export function resetSdk() {
    sdkLoads.clear();
    scriptLoads.clear();
    extraNamespaces = 0;
}

/**
 * Calls an optional callback.
 *
 * @param {Function|undefined} callback Callback.
 * @param {...unknown}         args     Arguments.
 *
 * @returns {unknown}
 */
function call( callback, ...args ) {
    return 'function' === typeof callback ? callback( ...args ) : undefined;
}

/**
 * Mounts PayPal's Smart Buttons (PayPal, Pay Later, Venmo).
 *
 * `onApprove` receives PayPal's approval data and may resolve with
 * `{ restart: true }` when the server's capture reported
 * `instrument_declined`: the buttons then reopen PayPal on the same Order
 * for the buyer to pick another funding source.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} container            Where the buttons render.
 * @param {object}      config               Client config.
 * @param {object}      [callbacks]          Callbacks.
 * @param {Function}    [callbacks.onApprove]    `( data ) => Promise<{ restart?: boolean }|void>`.
 * @param {Function}    [callbacks.onCancel]     `( data ) => void`.
 * @param {Function}    [callbacks.onError]      `( error ) => void`.
 * @param {Function}    [callbacks.onIneligible] `() => void`, when PayPal can't be offered.
 * @param {Function}    [callbacks.onClick]      `( data ) => void`.
 *
 * @returns {Promise<{ eligible: boolean, close: () => void }>}
 */
export async function mountButtons( container, config, callbacks = {} ) {
    const paypal  = await loadSdk( config, { components: [ 'buttons' ] } );
    const options = optionsOf( config );
    const buttons = paypal.Buttons( {
        style: options.button || {},
        onClick: ( data ) => call( callbacks.onClick, data ),
        createOrder: () => orderIdOf( config ),
        onApprove: async ( data, actions ) => {
            const result = await call( callbacks.onApprove, data );

            if ( result && result.restart && actions && 'function' === typeof actions.restart ) {
                return actions.restart();
            }

            return undefined;
        },
        onCancel: ( data ) => call( callbacks.onCancel, data ),
        onError: ( error ) => call( callbacks.onError, error ),
    } );

    if ( 'function' === typeof buttons.isEligible && ! buttons.isEligible() ) {
        call( callbacks.onIneligible );

        return { eligible: false, close: () => {} };
    }

    await buttons.render( container );

    return { eligible: true, close: () => call( buttons.close?.bind( buttons ) ) };
}

/**
 * Resolves a CSS color (including `oklch()` and `var()` values daisyUI
 * themes use) to `rgb()`/`rgba()`, which PayPal's hosted fields accept.
 * Falls back to the value as given when the browser can't paint it.
 *
 * @param {string}      value   CSS color.
 * @param {HTMLElement} element Element whose custom properties apply.
 *
 * @returns {string}
 */
function resolveColor( value, element ) {
    if ( ! value ) {
        return '';
    }

    const probe = document.createElement( 'span' );

    probe.style.color   = value;
    probe.style.display = 'none';
    element.appendChild( probe );

    const computed = window.getComputedStyle( probe ).color || value;

    probe.remove();

    const canvas  = document.createElement( 'canvas' );
    let   context = null;

    try {
        context = canvas.getContext?.( '2d', { willReadFrequently: true } ) ?? null;
    } catch {
        context = null;
    }

    if ( ! context ) {
        return computed;
    }

    canvas.width  = 1;
    canvas.height = 1;
    context.fillStyle = computed;
    context.fillRect( 0, 0, 1, 1 );

    const [ red, green, blue, alpha ] = context.getImageData( 0, 0, 1, 1 ).data;

    return 255 === alpha ? `rgb(${ red }, ${ green }, ${ blue })` : `rgba(${ red }, ${ green }, ${ blue }, ${ ( alpha / 255 ).toFixed( 2 ) })`;
}

/**
 * The hosted card fields' style, taken from the page's daisyUI tokens
 * (`--color-base-content`, `--color-error`) and font, so the fields match
 * the theme the host compiled. PayPal only takes plain colors and a few
 * font and spacing properties inside its frames; borders and backgrounds
 * belong on the containers.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} element Element inside the themed page.
 *
 * @returns {object} A `CardFields` `style` object.
 */
export function cardFieldStyle( element ) {
    const computed = window.getComputedStyle( element );
    const token    = ( name, fallback ) => resolveColor( computed.getPropertyValue( name ).trim() || fallback, element );
    const color    = token( '--color-base-content', computed.color || '#1f2937' );
    const font     = computed.fontFamily || 'system-ui, sans-serif';

    return {
        input: { color, 'font-family': font, 'font-size': '16px', padding: '0.75rem' },
        '.invalid': { color: token( '--color-error', '#b91c1c' ) },
        ':focus': { color },
    };
}

/**
 * Names a hosted field's iframe after the field's visible label (the
 * element its container's `aria-labelledby` points at), so screen readers
 * announce the store's translated label rather than PayPal's English title.
 * Leaves PayPal's title when there is no label.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} container Field container.
 *
 * @returns {void}
 */
function titleFrame( container ) {
    const frame = container.querySelector( 'iframe' );
    const label = container.getAttribute( 'aria-labelledby' );

    if ( frame && label ) {
        frame.title = document.getElementById( label )?.textContent?.trim() || frame.title;
    }
}

/**
 * Mounts PayPal's hosted card fields. `fields` maps `number`, `expiry`,
 * `cvv`, and (optionally) `name` to their containers.
 *
 * `onChange` gets PayPal's field state on every change and `onBlur` when a
 * field loses focus (falling back to `onChange`), so a wrapper can clear an
 * error while the shopper types and show one once they move on. The style
 * defaults to {@see cardFieldStyle} of the first container. Each rendered
 * iframe is titled with its container's `aria-labelledby` label.
 *
 * @since 1.0.0
 *
 * @param {Record<string, HTMLElement>} fields    Field containers.
 * @param {object}                      config    Client config.
 * @param {object}                      [callbacks] Callbacks: `onApprove`, `onError`, `onIneligible`, `onChange`, `onBlur`, and `style`.
 *
 * @returns {Promise<{ eligible: boolean, submit: ( options?: object ) => Promise<unknown>, getState: () => Promise<object|undefined>, close: () => void }>}
 */
export async function mountCardFields( fields, config, callbacks = {} ) {
    const paypal = await loadSdk( config, { components: [ 'card-fields' ] } );
    const first  = Object.values( fields ).find( Boolean );
    const card   = paypal.CardFields( {
        style: callbacks.style || ( first ? cardFieldStyle( first ) : {} ),
        createOrder: () => orderIdOf( config ),
        onApprove: ( data ) => call( callbacks.onApprove, data ),
        onError: ( error ) => call( callbacks.onError, error ),
        inputEvents: {
            onChange: ( state ) => call( callbacks.onChange, state ),
            onBlur: ( state ) => call( callbacks.onBlur || callbacks.onChange, state ),
        },
    } );

    if ( ! card.isEligible() ) {
        call( callbacks.onIneligible );

        return { eligible: false, submit: async () => undefined, getState: async () => undefined, close: () => {} };
    }

    const renderers = { name: 'NameField', number: 'NumberField', expiry: 'ExpiryField', cvv: 'CVVField' };
    const rendered  = [];

    for ( const [ field, method ] of Object.entries( renderers ) ) {
        if ( fields[ field ] ) {
            const instance = card[ method ]();

            rendered.push( instance );
            await instance.render( fields[ field ] );
            titleFrame( fields[ field ] );
        }
    }

    return {
        eligible: true,
        submit: ( submitOptions ) => card.submit( submitOptions ),
        getState: async () => ( 'function' === typeof card.getState ? card.getState() : undefined ),
        close: () => rendered.forEach( ( instance ) => call( instance.close?.bind( instance ) ) ),
    };
}

/**
 * Mounts an Apple Pay button, when the device and PayPal say Apple Pay is
 * available.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} container Where the button renders.
 * @param {object}      config    Client config.
 * @param {object}      [callbacks] Callbacks: `onApprove`, `onCancel`, `onError`, `onIneligible`, and `label` (the total's label on the payment sheet).
 *
 * @returns {Promise<{ eligible: boolean, close: () => void }>}
 */
export async function mountApplePay( container, config, callbacks = {} ) {
    const ApplePay = window.ApplePaySession;

    if ( ! ApplePay || ! ApplePay.canMakePayments?.() ) {
        call( callbacks.onIneligible );

        return { eligible: false, close: () => {} };
    }

    const [ paypal ] = await Promise.all( [ loadSdk( config, { components: [ 'applepay' ] } ), loadScript( APPLE_PAY_SDK_URL ) ] );
    const applepay   = paypal.Applepay();
    const settings   = await applepay.config();

    if ( ! settings?.isEligible ) {
        call( callbacks.onIneligible );

        return { eligible: false, close: () => {} };
    }

    const options = optionsOf( config );
    const button  = document.createElement( 'apple-pay-button' );

    button.setAttribute( 'buttonstyle', 'black' );
    button.setAttribute( 'type', 'buy' );
    button.setAttribute( 'locale', String( options.locale || 'en_US' ).replace( '_', '-' ) );

    button.addEventListener( 'click', () => {
        // Read when clicked: the host may have replaced the Order since.
        const orderId = orderIdOf( config );
        const session = new ApplePay( 4, {
            countryCode: settings.countryCode,
            merchantCapabilities: settings.merchantCapabilities,
            supportedNetworks: settings.supportedNetworks,
            currencyCode: String( options.currency || 'USD' ).toUpperCase(),
            requiredBillingContactFields: [ 'postalAddress' ],
            total: { label: callbacks.label || 'Total', amount: String( options.amount || '0.00' ), type: 'final' },
        } );

        session.onvalidatemerchant = ( event ) => {
            applepay.validateMerchant( { validationUrl: event.validationURL } )
                .then( ( result ) => session.completeMerchantValidation( result.merchantSession ) )
                .catch( ( error ) => {
                    session.abort();
                    call( callbacks.onError, error );
                } );
        };

        // The sheet only shows success once PayPal confirmed the Order. A
        // failing onApprove after that reports the error without completing
        // the (already closed) sheet a second time.
        session.onpaymentauthorized = async ( event ) => {
            let completed = false;

            try {
                await applepay.confirmOrder( { orderId, token: event.payment.token, billingContact: event.payment.billingContact } );
                session.completePayment( ApplePay.STATUS_SUCCESS );
                completed = true;
                await call( callbacks.onApprove, { orderID: orderId } );
            } catch ( error ) {
                if ( ! completed ) {
                    try {
                        session.completePayment( ApplePay.STATUS_FAILURE );
                    } catch {
                        // The sheet may already be gone.
                    }
                }

                call( callbacks.onError, error );
            }
        };

        session.oncancel = () => call( callbacks.onCancel, { orderID: orderId } );
        session.begin();
    } );

    container.appendChild( button );

    return { eligible: true, close: () => button.remove() };
}

/**
 * Mounts a Google Pay button, when Google Pay is ready on the device.
 * A `PAYER_ACTION_REQUIRED` answer runs PayPal's 3-D Secure step first.
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} container Where the button renders.
 * @param {object}      config    Client config.
 * @param {object}      [callbacks] Callbacks: `onApprove`, `onCancel`, `onError`, `onIneligible`.
 *
 * @returns {Promise<{ eligible: boolean, close: () => void }>}
 */
export async function mountGooglePay( container, config, callbacks = {} ) {
    const [ paypal ] = await Promise.all( [ loadSdk( config, { components: [ 'googlepay' ] } ), loadScript( GOOGLE_PAY_SDK_URL ) ] );
    const googlepay  = paypal.Googlepay();
    const settings   = await googlepay.config();

    if ( ! settings || false === settings.isEligible ) {
        call( callbacks.onIneligible );

        return { eligible: false, close: () => {} };
    }

    const options = optionsOf( config );
    const client  = new window.google.payments.api.PaymentsClient( { environment: 'live' === options.mode ? 'PRODUCTION' : 'TEST' } );
    const base    = { apiVersion: settings.apiVersion, apiVersionMinor: settings.apiVersionMinor };
    const ready   = await client.isReadyToPay( { ...base, allowedPaymentMethods: settings.allowedPaymentMethods } );

    if ( ! ready?.result ) {
        call( callbacks.onIneligible );

        return { eligible: false, close: () => {} };
    }

    const button = client.createButton( {
        buttonType: 'pay',
        buttonSizeMode: 'fill',
        buttonLocale: String( options.locale || 'en' ).slice( 0, 2 ),
        onClick: async () => {
            // Read when clicked: the host may have replaced the Order since.
            const orderId = orderIdOf( config );

            try {
                const data = await client.loadPaymentData( {
                    ...base,
                    allowedPaymentMethods: settings.allowedPaymentMethods,
                    merchantInfo: settings.merchantInfo,
                    transactionInfo: {
                        countryCode: settings.countryCode,
                        currencyCode: String( options.currency || 'USD' ).toUpperCase(),
                        totalPriceStatus: 'FINAL',
                        totalPrice: String( options.amount || '0.00' ),
                    },
                } );
                const result = await googlepay.confirmOrder( { orderId, paymentMethodData: data.paymentMethodData } );

                if ( 'PAYER_ACTION_REQUIRED' === result?.status ) {
                    await googlepay.initiatePayerAction( { orderId } );
                }

                await call( callbacks.onApprove, { orderID: orderId } );
            } catch ( error ) {
                if ( 'CANCELED' === error?.statusCode ) {
                    call( callbacks.onCancel, { orderID: orderId } );
                } else {
                    call( callbacks.onError, error );
                }
            }
        },
    } );

    container.appendChild( button );

    return { eligible: true, close: () => button.remove() };
}

/**
 * Renders PayPal's Pay Later message. It renders nothing when the buyer or
 * amount isn't eligible (PayPal decides).
 *
 * @since 1.0.0
 *
 * @param {HTMLElement} container          Where the message renders.
 * @param {object}      config             Client config (`options.amount` is the default amount).
 * @param {object}      [settings]         Settings.
 * @param {string}      [settings.amount]  Amount, as PayPal writes it (`240.00`).
 * @param {string}      [settings.placement] `product`, `cart`, or `payment`.
 * @param {object}      [settings.style]   Message style.
 * @param {Function}    [settings.onRender] Called once rendered.
 *
 * @returns {Promise<{ update: ( amount: string ) => Promise<void> }>}
 */
export async function mountMessages( container, config, settings = {} ) {
    const paypal  = await loadSdk( config, { components: [ 'messages' ] } );
    const options = optionsOf( config );
    const render  = async ( amount ) => {
        await paypal.Messages( {
            amount: String( amount ),
            currency: String( options.currency || 'USD' ).toUpperCase(),
            placement: settings.placement || 'payment',
            style: settings.style || { layout: 'text' },
            onRender: () => call( settings.onRender ),
        } ).render( container );
    };

    await render( settings.amount ?? options.amount ?? '0.00' );

    return { update: ( amount ) => render( amount ) };
}
