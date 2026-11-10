/**
 * A stand-in for `paypal-checkout.js` in the browser suite.
 *
 * It exports the module's mount functions without loading PayPal's SDK.
 * Each mount records its callbacks on `window.paypalFake.mounted` (keyed
 * `buttons`, `card`, `applePay`, `googlePay`), so a test can play PayPal's
 * part: approve, cancel, or fail. Set `window.paypalFake.eligible = false`
 * before a driver mounts to have it report ineligible.
 *
 * @since 1.0.0
 */

/**
 * The shared fake state.
 *
 * @returns {{ eligible: boolean, mounted: Record<string, object>, mounts: number }}
 */
function fake() {
    window.paypalFake         = window.paypalFake || {};
    window.paypalFake.mounted = window.paypalFake.mounted || {};
    window.paypalFake.mounts  = window.paypalFake.mounts || 0;

    return window.paypalFake;
}

/**
 * The Order id the config names.
 *
 * @param {object} config Client config.
 *
 * @returns {string}
 */
export function orderIdOf( config ) {
    return String( config?.options?.order_id || config?.client_secret || '' );
}

/**
 * Records a mount.
 *
 * @param {string} key       Mount key.
 * @param {object} config    Client config.
 * @param {object} callbacks Callbacks.
 *
 * @returns {boolean} Whether the fake is eligible.
 */
function record( key, config, callbacks ) {
    const state = fake();

    state.mounts++;
    state.mounted[ key ] = { config, callbacks, orderId: () => orderIdOf( config ) };

    if ( false === state.eligible ) {
        callbacks.onIneligible?.();

        return false;
    }

    return true;
}

export async function mountButtons( container, config, callbacks = {} ) {
    if ( ! record( 'buttons', config, callbacks ) ) {
        return { eligible: false, close: () => {} };
    }

    const button = document.createElement( 'button' );

    button.type        = 'button';
    button.textContent = 'PayPal (fake)';
    button.setAttribute( 'data-paypal-fake-button', '' );
    container.appendChild( button );

    return { eligible: true, close: () => button.remove() };
}

export async function mountCardFields( fields, config, callbacks = {} ) {
    if ( ! record( 'card', config, callbacks ) ) {
        return { eligible: false, submit: async () => undefined, getState: async () => undefined, close: () => {} };
    }

    return {
        eligible: true,
        // PayPal approves the card, then calls onApprove.
        submit: async () => {
            window.setTimeout( () => callbacks.onApprove?.( { orderID: orderIdOf( config ) } ), 0 );
        },
        getState: async () => ( { isFormValid: true, fields: {} } ),
        close: () => {},
    };
}

export async function mountApplePay( container, config, callbacks = {} ) {
    return { eligible: record( 'applePay', config, callbacks ), close: () => {} };
}

export async function mountGooglePay( container, config, callbacks = {} ) {
    return { eligible: record( 'googlePay', config, callbacks ), close: () => {} };
}

export async function mountMessages() {
    return { update: async () => undefined };
}
