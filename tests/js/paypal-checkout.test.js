import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    APPLE_PAY_SDK_URL,
    GOOGLE_PAY_SDK_URL,
    SDK_URL,
    cardFieldStyle,
    cspNonce,
    loadSdk,
    loadScript,
    mountApplePay,
    mountButtons,
    mountCardFields,
    mountGooglePay,
    mountMessages,
    orderIdOf,
    resetSdk,
    sdkParams,
    sdkUrl,
} from '../../resources/js/paypal-checkout.js';

/**
 * A client config like `PayPalGateway::clientConfig()` returns.
 *
 * @param {object} options Option overrides.
 *
 * @returns {object}
 */
function clientConfig( options = {} ) {
    return {
        driver: 'paypal-buttons',
        flow: 'embedded',
        publishable_key: 'client-id',
        client_secret: 'ORDER-1',
        options: {
            order_id: 'ORDER-1',
            amount: '25.00',
            currency: 'usd',
            intent: 'capture',
            mode: 'sandbox',
            locale: 'en_US',
            components: [ 'messages', 'buttons' ],
            enable_funding: [ 'venmo', 'paylater' ],
            disable_funding: [ 'card' ],
            ...options,
        },
    };
}

/**
 * The PayPal SDK scripts on the page.
 *
 * @returns {HTMLScriptElement[]}
 */
function sdkScripts() {
    return [ ...document.querySelectorAll( 'script' ) ].filter( ( script ) => script.src.startsWith( SDK_URL ) );
}

/**
 * Finishes loading `script`, exposing `sdk` under its namespace.
 *
 * @param {HTMLScriptElement} script Script.
 * @param {object}            sdk    Fake SDK.
 *
 * @returns {void}
 */
function finishLoading( script, sdk ) {
    window[ script.getAttribute( 'data-namespace' ) || 'paypal' ] = sdk;
    script.dispatchEvent( new Event( 'load' ) );
}

beforeEach( () => {
    // jsdom has no canvas; cardFieldStyle() then keeps the computed color.
    vi.spyOn( HTMLCanvasElement.prototype, 'getContext' ).mockReturnValue( null );
    resetSdk();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete window.paypal;
} );

afterEach( () => {
    vi.restoreAllMocks();
    delete window.ApplePaySession;
    delete window.google;
} );

/**
 * Finishes loading the third-party script at `src`.
 *
 * @param {string} src Script URL.
 *
 * @returns {void}
 */
function finishScript( src ) {
    [ ...document.querySelectorAll( 'script' ) ].find( ( script ) => script.src === src )?.dispatchEvent( new Event( 'load' ) );
}

/**
 * Lets pending promise callbacks run.
 *
 * @returns {Promise<void>}
 */
function flush() {
    return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

describe( 'sdk url', () => {
    it( 'builds the v5 SDK url from the client config', () => {
        const params = sdkParams( clientConfig() );

        expect( Object.fromEntries( params ) ).toEqual( {
            'client-id': 'client-id',
            components: 'buttons,messages',
            currency: 'USD',
            intent: 'capture',
            commit: 'true',
            locale: 'en_US',
            'enable-funding': 'venmo,paylater',
            'disable-funding': 'card',
        } );
        expect( sdkUrl( clientConfig() ) ).toMatch( /^https:\/\/www\.paypal\.com\/sdk\/js\?client-id=client-id&/ );
    } );

    it( 'merges, dedupes, and sorts components', () => {
        expect( sdkParams( clientConfig(), [ 'card-fields', 'buttons' ] ).get( 'components' ) ).toBe( 'buttons,card-fields,messages' );
    } );

    it( 'uses the authorize intent only when asked', () => {
        expect( sdkParams( clientConfig( { intent: 'authorize' } ) ).get( 'intent' ) ).toBe( 'authorize' );
        expect( sdkParams( clientConfig( { intent: 'whatever' } ) ).get( 'intent' ) ).toBe( 'capture' );
    } );

    it( 'sends buyer-country only in the sandbox', () => {
        expect( sdkParams( clientConfig( { buyer_country: 'de' } ) ).get( 'buyer-country' ) ).toBe( 'DE' );
        expect( sdkParams( clientConfig( { buyer_country: 'de', mode: 'live' } ) ).has( 'buyer-country' ) ).toBe( false );
    } );

    it( 'leaves out empty funding lists', () => {
        const params = sdkParams( clientConfig( { enable_funding: [], disable_funding: '' } ) );

        expect( params.has( 'enable-funding' ) ).toBe( false );
        expect( params.has( 'disable-funding' ) ).toBe( false );
    } );

    it( 'never puts anything but the public client id in the url', () => {
        const url = sdkUrl( { ...clientConfig(), client_secret: 'ORDER-1' } );

        expect( url ).not.toContain( 'ORDER-1' );
    } );

    it( 'reads the Order id from the options or the client secret', () => {
        expect( orderIdOf( clientConfig() ) ).toBe( 'ORDER-1' );
        expect( orderIdOf( { client_secret: 'ORDER-2', options: {} } ) ).toBe( 'ORDER-2' );
    } );
} );

describe( 'loading', () => {
    it( 'loads the SDK once for concurrent and later calls', async () => {
        const sdk   = { Buttons: vi.fn() };
        const first = loadSdk( clientConfig() );
        const again = loadSdk( clientConfig(), { components: [ 'messages' ] } );

        expect( sdkScripts() ).toHaveLength( 1 );

        finishLoading( sdkScripts()[ 0 ], sdk );

        await expect( first ).resolves.toBe( sdk );
        await expect( again ).resolves.toBe( sdk );
        await expect( loadSdk( clientConfig() ) ).resolves.toBe( sdk );
        expect( sdkScripts() ).toHaveLength( 1 );
    } );

    it( 'loads the merged components under a new namespace when more are needed', async () => {
        const first = loadSdk( clientConfig() );

        finishLoading( sdkScripts()[ 0 ], { name: 'first' } );
        await first;

        const second = loadSdk( clientConfig(), { components: [ 'card-fields' ] } );
        const script = sdkScripts()[ 1 ];

        expect( script.getAttribute( 'data-namespace' ) ).toMatch( /^paypal_ap_\d+$/ );
        expect( new URL( script.src ).searchParams.get( 'components' ) ).toBe( 'buttons,card-fields,messages' );

        finishLoading( script, { name: 'second' } );

        await expect( second ).resolves.toEqual( { name: 'second' } );
        await expect( loadSdk( clientConfig(), { components: [ 'buttons' ] } ) ).resolves.toEqual( { name: 'second' } );
        expect( sdkScripts() ).toHaveLength( 2 );
    } );

    it( 'sets the vault id token and partner attribution as script attributes', () => {
        loadSdk( clientConfig( { user_id_token: 'id-token', partner_attribution_id: 'BN-CODE' } ) );

        const script = sdkScripts()[ 0 ];

        expect( script.getAttribute( 'data-user-id-token' ) ).toBe( 'id-token' );
        expect( script.getAttribute( 'data-partner-attribution-id' ) ).toBe( 'BN-CODE' );
        expect( script.src ).not.toContain( 'id-token' );
    } );

    it( 'adds no nonce when the page uses none', () => {
        loadSdk( clientConfig() );

        const script = sdkScripts()[ 0 ];

        expect( script.hasAttribute( 'nonce' ) ).toBe( false );
        expect( script.hasAttribute( 'data-csp-nonce' ) ).toBe( false );
        expect( cspNonce() ).toBe( '' );
    } );

    it( 'gives the SDK the page\'s CSP nonce, as nonce and data-csp-nonce', () => {
        const nonced = document.createElement( 'script' );
        nonced.setAttribute( 'nonce', 'page-nonce' );
        document.head.appendChild( nonced );

        loadSdk( clientConfig() );

        const script = sdkScripts()[ 0 ];

        expect( script.getAttribute( 'nonce' ) ).toBe( 'page-nonce' );
        expect( script.getAttribute( 'data-csp-nonce' ) ).toBe( 'page-nonce' );
    } );

    it( 'prefers the host\'s csp_nonce option over the page\'s', () => {
        const nonced = document.createElement( 'script' );
        nonced.setAttribute( 'nonce', 'page-nonce' );
        document.head.appendChild( nonced );

        loadSdk( clientConfig( { csp_nonce: 'host-nonce' } ) );

        expect( sdkScripts()[ 0 ].getAttribute( 'data-csp-nonce' ) ).toBe( 'host-nonce' );
    } );

    it( 'gives other scripts it loads the page\'s CSP nonce', () => {
        const nonced = document.createElement( 'script' );
        nonced.setAttribute( 'nonce', 'page-nonce' );
        document.head.appendChild( nonced );

        loadScript( GOOGLE_PAY_SDK_URL );

        const script = [ ...document.head.querySelectorAll( 'script' ) ].find( ( element ) => element.src === GOOGLE_PAY_SDK_URL );

        expect( script.getAttribute( 'nonce' ) ).toBe( 'page-nonce' );
    } );

    it( 'lets a later call retry after the script failed to load', async () => {
        const failed = loadSdk( clientConfig() );

        sdkScripts()[ 0 ].dispatchEvent( new Event( 'error' ) );

        await expect( failed ).rejects.toThrow( 'Could not load' );
        expect( sdkScripts() ).toHaveLength( 0 );

        const retried = loadSdk( clientConfig() );

        finishLoading( sdkScripts()[ 0 ], { ok: true } );

        await expect( retried ).resolves.toEqual( { ok: true } );
    } );

    it( 'rejects when the script loads without the SDK', async () => {
        const load = loadSdk( clientConfig() );

        sdkScripts()[ 0 ].dispatchEvent( new Event( 'load' ) );

        await expect( load ).rejects.toThrow( 'did not load' );
    } );
} );

describe( 'buttons', () => {
    /**
     * Mounts the buttons against a fake SDK.
     *
     * @param {object} callbacks Callbacks.
     * @param {object} [overrides] Fake `Buttons()` result overrides.
     *
     * @returns {Promise<{ result: object, settings: object, instance: object }>}
     */
    async function mount( callbacks, overrides = {} ) {
        const instance = { isEligible: () => true, render: vi.fn( async () => undefined ), close: vi.fn(), ...overrides };
        let settings   = null;

        window.paypal = {
            Buttons: ( given ) => {
                settings = given;

                return instance;
            },
        };

        const container = document.createElement( 'div' );
        const mounting  = mountButtons( container, clientConfig( { button: { color: 'blue' } } ), callbacks );

        finishLoading( sdkScripts()[ 0 ], window.paypal );

        const result = await mounting;

        return { result, settings, instance, container };
    }

    it( 'renders the buttons for the server-created Order', async () => {
        const { result, settings, instance, container } = await mount( {} );

        expect( result.eligible ).toBe( true );
        expect( settings.createOrder() ).toBe( 'ORDER-1' );
        expect( settings.style ).toEqual( { color: 'blue' } );
        expect( instance.render ).toHaveBeenCalledWith( container );
    } );

    it( 'hands approval to onApprove', async () => {
        const onApprove    = vi.fn( async () => undefined );
        const { settings } = await mount( { onApprove } );
        const restart      = vi.fn();

        await settings.onApprove( { orderID: 'ORDER-1' }, { restart } );

        expect( onApprove ).toHaveBeenCalledWith( { orderID: 'ORDER-1' } );
        expect( restart ).not.toHaveBeenCalled();
    } );

    it( 'restarts on the same Order when onApprove asks to', async () => {
        const { settings } = await mount( { onApprove: async () => ( { restart: true } ) } );
        const restart      = vi.fn( () => 'restarted' );

        await expect( settings.onApprove( { orderID: 'ORDER-1' }, { restart } ) ).resolves.toBe( 'restarted' );
    } );

    it( 'passes cancels and errors on', async () => {
        const onCancel     = vi.fn();
        const onError      = vi.fn();
        const { settings } = await mount( { onCancel, onError } );
        const error        = new Error( 'boom' );

        settings.onCancel( { orderID: 'ORDER-1' } );
        settings.onError( error );

        expect( onCancel ).toHaveBeenCalledWith( { orderID: 'ORDER-1' } );
        expect( onError ).toHaveBeenCalledWith( error );
    } );

    it( 'reports ineligible buttons without rendering them', async () => {
        const onIneligible               = vi.fn();
        const { result, instance }       = await mount( { onIneligible }, { isEligible: () => false } );

        expect( result.eligible ).toBe( false );
        expect( onIneligible ).toHaveBeenCalled();
        expect( instance.render ).not.toHaveBeenCalled();
    } );
} );

describe( 'messages', () => {
    it( 'renders the Pay Later message for an amount and placement, and updates it', async () => {
        const rendered  = [];
        const container = document.createElement( 'div' );

        window.paypal = {
            Messages: ( settings ) => ( { render: async ( target ) => rendered.push( { ...settings, target } ) } ),
        };

        const mounting = mountMessages( container, clientConfig(), { amount: '240.00', placement: 'product' } );

        finishLoading( sdkScripts()[ 0 ], window.paypal );

        const message = await mounting;

        await message.update( '60.00' );

        expect( rendered.map( ( { amount, placement, currency, target } ) => ( { amount, placement, currency, target } ) ) ).toEqual( [
            { amount: '240.00', placement: 'product', currency: 'USD', target: container },
            { amount: '60.00', placement: 'product', currency: 'USD', target: container },
        ] );
    } );
} );

describe( 'card fields', () => {
    /**
     * Mounts the card fields against a fake SDK.
     *
     * @param {object} callbacks  Callbacks.
     * @param {object} [overrides] Fake `CardFields()` result overrides.
     *
     * @returns {Promise<{ result: object, settings: object, card: object, fields: object }>}
     */
    async function mount( callbacks, overrides = {} ) {
        const field  = () => ( { render: vi.fn( async () => undefined ), close: vi.fn() } );
        const card   = {
            isEligible: () => true,
            NameField: vi.fn( field ),
            NumberField: vi.fn( field ),
            ExpiryField: vi.fn( field ),
            CVVField: vi.fn( field ),
            submit: vi.fn( async () => undefined ),
            getState: vi.fn( async () => ( { isFormValid: true } ) ),
            ...overrides,
        };
        let settings = null;

        window.paypal = {
            CardFields: ( given ) => {
                settings = given;

                return card;
            },
        };

        const fields   = { number: document.createElement( 'div' ), expiry: document.createElement( 'div' ), cvv: document.createElement( 'div' ) };
        const mounting = mountCardFields( fields, clientConfig( { components: [ 'card-fields' ] } ), callbacks );

        Object.values( fields ).forEach( ( element ) => document.body.appendChild( element ) );
        finishLoading( sdkScripts()[ 0 ], window.paypal );

        const result = await mounting;

        return { result, settings, card, fields };
    }

    it( 'renders the given fields for the server-created Order with a themed style', async () => {
        const { result, settings, card } = await mount( {} );

        expect( result.eligible ).toBe( true );
        expect( settings.createOrder() ).toBe( 'ORDER-1' );
        expect( card.NumberField ).toHaveBeenCalled();
        expect( card.NameField ).not.toHaveBeenCalled();
        expect( settings.style ).toHaveProperty( 'input' );
        expect( settings.style ).toHaveProperty( '.invalid' );
    } );

    it( 'reads the Order id when the fields submit, so a replaced Order is used', async () => {
        const config   = clientConfig( { components: [ 'card-fields' ] } );
        let settings   = null;

        window.paypal = { CardFields: ( given ) => {
            settings = given;

            return { isEligible: () => true, NumberField: () => ( { render: async () => undefined } ), submit: async () => undefined };
        } };

        const mounting = mountCardFields( { number: document.createElement( 'div' ) }, config, {} );

        finishLoading( sdkScripts()[ 0 ], window.paypal );
        await mounting;

        config.options.order_id = 'ORDER-2';

        expect( settings.createOrder() ).toBe( 'ORDER-2' );
    } );

    it( 'sends changes and blurs to their own callbacks, falling back to onChange', async () => {
        const onChange     = vi.fn();
        const onBlur       = vi.fn();
        const { settings } = await mount( { onChange, onBlur } );

        settings.inputEvents.onChange( { emittedBy: 'number' } );
        settings.inputEvents.onBlur( { emittedBy: 'cvv' } );

        expect( onChange ).toHaveBeenCalledWith( { emittedBy: 'number' } );
        expect( onBlur ).toHaveBeenCalledWith( { emittedBy: 'cvv' } );

        resetSdk();
        document.head.innerHTML = '';

        const fallback = vi.fn();
        const again    = await mount( { onChange: fallback } );

        again.settings.inputEvents.onBlur( { emittedBy: 'expiry' } );

        expect( fallback ).toHaveBeenCalledWith( { emittedBy: 'expiry' } );
    } );

    it( 'submits and reports the field state', async () => {
        const { result, card } = await mount( {} );

        await result.submit();

        expect( card.submit ).toHaveBeenCalled();
        await expect( result.getState() ).resolves.toEqual( { isFormValid: true } );
    } );

    it( 'passes approval and errors on', async () => {
        const onApprove    = vi.fn();
        const onError      = vi.fn();
        const { settings } = await mount( { onApprove, onError } );

        settings.onApprove( { orderID: 'ORDER-1', liabilityShift: 'POSSIBLE' } );
        settings.onError( new Error( 'declined' ) );

        expect( onApprove ).toHaveBeenCalledWith( { orderID: 'ORDER-1', liabilityShift: 'POSSIBLE' } );
        expect( onError ).toHaveBeenCalled();
    } );

    it( 'reports ineligible card fields without rendering them', async () => {
        const onIneligible     = vi.fn();
        const { result, card } = await mount( { onIneligible }, { isEligible: () => false } );

        expect( result.eligible ).toBe( false );
        expect( onIneligible ).toHaveBeenCalled();
        expect( card.NumberField ).not.toHaveBeenCalled();
    } );

    it( 'styles the fields from the page\'s daisyUI tokens', () => {
        const element = document.createElement( 'div' );

        element.style.setProperty( '--color-base-content', 'rgb(10, 20, 30)' );
        element.style.setProperty( '--color-error', 'rgb(200, 0, 0)' );
        element.style.fontFamily = 'Inter, sans-serif';
        document.body.appendChild( element );

        const style = cardFieldStyle( element );

        expect( style.input ).toMatchObject( { color: 'rgb(10, 20, 30)', 'font-family': 'Inter, sans-serif', 'font-size': '16px' } );
        expect( style[ '.invalid' ] ).toEqual( { color: 'rgb(200, 0, 0)' } );
    } );
} );

describe( 'apple pay', () => {
    it( 'reports a device without Apple Pay as ineligible without loading anything', async () => {
        const onIneligible = vi.fn();
        const result       = await mountApplePay( document.createElement( 'div' ), clientConfig(), { onIneligible } );

        expect( result.eligible ).toBe( false );
        expect( onIneligible ).toHaveBeenCalled();
        expect( document.querySelectorAll( 'script' ) ).toHaveLength( 0 );
    } );

    /**
     * Mounts Apple Pay on a device that supports it.
     *
     * @param {object} config    Fake `Applepay().config()` answer.
     * @param {object} callbacks Callbacks.
     *
     * @returns {Promise<{ result: object, container: HTMLElement, applepay: object, sessions: object[] }>}
     */
    async function mount( config, callbacks = {} ) {
        const sessions = [];

        window.ApplePaySession = class {
            static STATUS_SUCCESS = 0;

            static STATUS_FAILURE = 1;

            static canMakePayments() {
                return true;
            }

            constructor( version, request ) {
                this.version = version;
                this.request = request;
                this.begin   = vi.fn();
                this.abort   = vi.fn();
                this.completePayment = vi.fn();
                this.completeMerchantValidation = vi.fn();
                sessions.push( this );
            }
        };

        const applepay  = {
            config: vi.fn( async () => config ),
            validateMerchant: vi.fn( async () => ( { merchantSession: { id: 'session' } } ) ),
            confirmOrder: vi.fn( async () => ( { approveApplePayPayment: { status: 'APPROVED' } } ) ),
        };
        const container = document.createElement( 'div' );

        window.paypal = { Applepay: () => applepay };

        const mounting = mountApplePay( container, clientConfig( { components: [ 'applepay' ] } ), callbacks );

        finishLoading( sdkScripts()[ 0 ], window.paypal );
        finishScript( APPLE_PAY_SDK_URL );

        const result = await mounting;

        return { result, container, applepay, sessions };
    }

    it( 'hides itself when PayPal says the merchant isn\'t eligible', async () => {
        const onIneligible          = vi.fn();
        const { result, container } = await mount( { isEligible: false }, { onIneligible } );

        expect( result.eligible ).toBe( false );
        expect( onIneligible ).toHaveBeenCalled();
        expect( container.children ).toHaveLength( 0 );
    } );

    it( 'shows the cart total and confirms through confirmOrder before onApprove', async () => {
        const onApprove                       = vi.fn( async () => undefined );
        const { container, applepay, sessions } = await mount( { isEligible: true, countryCode: 'US', merchantCapabilities: [ 'supports3DS' ], supportedNetworks: [ 'visa' ] }, { onApprove, label: 'Shop' } );

        container.querySelector( 'apple-pay-button' ).dispatchEvent( new Event( 'click' ) );

        const [ session ] = sessions;

        expect( session.request ).toMatchObject( { currencyCode: 'USD', total: { label: 'Shop', amount: '25.00', type: 'final' } } );
        expect( session.begin ).toHaveBeenCalled();

        await session.onpaymentauthorized( { payment: { token: 'apple-token', billingContact: {} } } );

        expect( applepay.confirmOrder ).toHaveBeenCalledWith( { orderId: 'ORDER-1', token: 'apple-token', billingContact: {} } );
        expect( session.completePayment ).toHaveBeenCalledWith( 0 );
        expect( onApprove ).toHaveBeenCalledWith( { orderID: 'ORDER-1' } );
        expect( applepay.confirmOrder.mock.invocationCallOrder[ 0 ] ).toBeLessThan( onApprove.mock.invocationCallOrder[ 0 ] );
    } );

    it( 'fails the sheet and reports the error when confirmOrder is refused', async () => {
        const onApprove                       = vi.fn();
        const onError                         = vi.fn();
        const { container, applepay, sessions } = await mount( { isEligible: true }, { onApprove, onError } );

        applepay.confirmOrder.mockRejectedValueOnce( new Error( 'declined' ) );
        container.querySelector( 'apple-pay-button' ).dispatchEvent( new Event( 'click' ) );
        await sessions[ 0 ].onpaymentauthorized( { payment: { token: 'apple-token' } } );

        expect( sessions[ 0 ].completePayment ).toHaveBeenCalledWith( 1 );
        expect( onError ).toHaveBeenCalled();
        expect( onApprove ).not.toHaveBeenCalled();
    } );
} );

describe( 'google pay', () => {
    /**
     * Mounts Google Pay against fake SDKs.
     *
     * @param {object} options             Options.
     * @param {object} [options.callbacks] Callbacks.
     * @param {object} [options.ready]     `isReadyToPay` answer.
     * @param {object} [options.confirmed] `confirmOrder` answer.
     * @param {string} [options.mode]      PayPal mode.
     *
     * @returns {Promise<{ result: object, container: HTMLElement, googlepay: object, client: object, environment: string }>}
     */
    async function mount( { callbacks = {}, ready = { result: true }, confirmed = { status: 'APPROVED' }, mode = 'sandbox' } = {} ) {
        let environment = null;
        let onClick     = null;
        const client    = {
            isReadyToPay: vi.fn( async () => ready ),
            loadPaymentData: vi.fn( async () => ( { paymentMethodData: { type: 'CARD' } } ) ),
            createButton: vi.fn( ( settings ) => {
                onClick = settings.onClick;

                return document.createElement( 'button' );
            } ),
        };
        const googlepay = {
            config: vi.fn( async () => ( { apiVersion: 2, apiVersionMinor: 0, allowedPaymentMethods: [ { type: 'CARD' } ], merchantInfo: { merchantId: 'M' }, countryCode: 'US' } ) ),
            confirmOrder: vi.fn( async () => confirmed ),
            initiatePayerAction: vi.fn( async () => undefined ),
        };

        window.google = { payments: { api: { PaymentsClient: class {
            constructor( settings ) {
                environment = settings.environment;

                return client;
            }
        } } } };
        window.paypal = { Googlepay: () => googlepay };

        const container = document.createElement( 'div' );
        const mounting  = mountGooglePay( container, clientConfig( { components: [ 'googlepay' ], mode } ), callbacks );

        finishLoading( sdkScripts()[ 0 ], window.paypal );
        finishScript( GOOGLE_PAY_SDK_URL );

        const result = await mounting;

        return { result, container, googlepay, client, environment, click: () => onClick() };
    }

    it( 'loads Google Pay once and renders only when isReadyToPay passes', async () => {
        const { result, container, client } = await mount();

        expect( result.eligible ).toBe( true );
        expect( container.children ).toHaveLength( 1 );
        expect( client.isReadyToPay ).toHaveBeenCalledWith( { apiVersion: 2, apiVersionMinor: 0, allowedPaymentMethods: [ { type: 'CARD' } ] } );
        expect( [ ...document.querySelectorAll( 'script' ) ].filter( ( script ) => script.src === GOOGLE_PAY_SDK_URL ) ).toHaveLength( 1 );
    } );

    it( 'hides itself when the device isn\'t ready to pay', async () => {
        const onIneligible          = vi.fn();
        const { result, container } = await mount( { ready: { result: false }, callbacks: { onIneligible } } );

        expect( result.eligible ).toBe( false );
        expect( onIneligible ).toHaveBeenCalled();
        expect( container.children ).toHaveLength( 0 );
    } );

    it( 'uses the TEST environment in the sandbox and PRODUCTION live', async () => {
        expect( ( await mount() ).environment ).toBe( 'TEST' );

        resetSdk();
        document.head.innerHTML = '';

        expect( ( await mount( { mode: 'live' } ) ).environment ).toBe( 'PRODUCTION' );
    } );

    it( 'confirms the Order with the payment data, then calls onApprove', async () => {
        const onApprove                  = vi.fn();
        const { googlepay, client, click } = await mount( { callbacks: { onApprove } } );

        await click();

        expect( client.loadPaymentData ).toHaveBeenCalledWith( expect.objectContaining( { transactionInfo: expect.objectContaining( { currencyCode: 'USD', totalPrice: '25.00', totalPriceStatus: 'FINAL' } ) } ) );
        expect( googlepay.confirmOrder ).toHaveBeenCalledWith( { orderId: 'ORDER-1', paymentMethodData: { type: 'CARD' } } );
        expect( googlepay.initiatePayerAction ).not.toHaveBeenCalled();
        expect( onApprove ).toHaveBeenCalledWith( { orderID: 'ORDER-1' } );
    } );

    it( 'runs 3-D Secure before onApprove when PayPal asks for payer action', async () => {
        const onApprove           = vi.fn();
        const { googlepay, click } = await mount( { confirmed: { status: 'PAYER_ACTION_REQUIRED' }, callbacks: { onApprove } } );

        await click();

        expect( googlepay.initiatePayerAction ).toHaveBeenCalledWith( { orderId: 'ORDER-1' } );
        expect( googlepay.initiatePayerAction.mock.invocationCallOrder[ 0 ] ).toBeLessThan( onApprove.mock.invocationCallOrder[ 0 ] );
    } );

    it( 'reports a closed sheet as a cancel and anything else as an error', async () => {
        const onCancel                = vi.fn();
        const onError                 = vi.fn();
        const onApprove               = vi.fn();
        const { client, googlepay, click } = await mount( { callbacks: { onCancel, onError, onApprove } } );

        client.loadPaymentData.mockRejectedValueOnce( { statusCode: 'CANCELED' } );
        await click();

        googlepay.confirmOrder.mockRejectedValueOnce( new Error( 'declined' ) );
        await click();
        await flush();

        expect( onCancel ).toHaveBeenCalledWith( { orderID: 'ORDER-1' } );
        expect( onError ).toHaveBeenCalledTimes( 1 );
        expect( onApprove ).not.toHaveBeenCalled();
    } );
} );
