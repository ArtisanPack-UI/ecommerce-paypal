import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SDK_URL,
    loadSdk,
    mountButtons,
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
    resetSdk();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete window.paypal;
} );

afterEach( () => {
    vi.restoreAllMocks();
} );

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
