{{--
    The Apple Pay and Google Pay drivers. See PayPalApplePay and
    PayPalGooglePay.

    The wallet button sits in `wire:ignore`; the script mounts it through
    `paypal-checkout.js` (`mountApplePay` or `mountGooglePay`), which checks
    the device and PayPal eligibility first. An ineligible wallet hides its
    button and says so politely instead of raising an alert.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div class="flex flex-col gap-3" data-payment-driver="{{ $driver }}">
    @if ( $available )
        <div wire:ignore class="flex flex-col gap-3">
            <p class="text-sm text-base-content/70" data-paypal-loading>{{ __( 'Loading :wallet…', [ 'wallet' => $wallet ] ) }}</p>

            <div role="group" aria-label="{{ __( 'Pay with :wallet', [ 'wallet' => $wallet ] ) }}" class="min-h-11" data-paypal-wallet></div>

            <p class="text-sm text-base-content/70 empty:hidden" role="status" aria-live="polite" data-paypal-status></p>

            <p class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" role="alert" aria-live="assertive" data-paypal-error></p>
        </div>
    @else
        <x-artisanpack-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-base-content!" :title="__( 'This payment method isn\'t available.' )" data-payment-unavailable />
    @endif
</div>

@if ( $available )
    @script
    <script>
        const root      = $wire.$el;
        const settings  = @js( $paypal );
        const container = root.querySelector( '[data-paypal-wallet]' );
        const loading   = root.querySelector( '[data-paypal-loading]' );
        const statusBox = root.querySelector( '[data-paypal-status]' );
        const errorBox  = root.querySelector( '[data-paypal-error]' );
        let quiet       = false;

        // Set while the checkout acts on an approved payment, with the
        // Livewire requests still in flight and the timer that waits for
        // them to settle.
        let awaitingOutcome = false;
        let inFlight        = 0;
        let settleTimer     = null;
        let leaving         = false;

        window.addEventListener( 'beforeunload', () => {
            leaving = true;
        }, { once: true } );

        // Clears first so the same message is announced again.
        const announce = ( box, message ) => {
            statusBox.textContent = '';
            errorBox.textContent  = '';
            window.requestAnimationFrame( () => {
                box.textContent = message;
            } );
        };

        // The step's heading: the placed order's while it waits for its
        // payment, otherwise the payment step's.
        const focusHeading = () => {
            for ( const id of [].concat( settings.heading ) ) {
                const element = document.getElementById( id );

                if ( element ) {
                    element.focus();

                    return;
                }
            }
        };

        // Focus is lost when it sits on the page body or on an element that
        // has since left the page.
        const focusLost = () => {
            const active = document.activeElement;

            return ! active || active === document.body || ! active.isConnected;
        };

        // A closed wallet sheet can leave focus on the page body: keep it
        // on the payment step, without taking it from another control.
        const showFailure = ( message ) => {
            if ( focusLost() ) {
                focusHeading();
            }

            announce( errorBox, message || settings.messages.failed );
        };

        const stopWaiting = () => {
            awaitingOutcome = false;
            window.clearTimeout( settleTimer );
        };

        // An approved payment either leaves the page, re-renders this
        // driver, or fails with `payment-failed`. When the checkout keeps
        // the shopper here instead (it couldn't place the order and shows
        // why), nothing reports back: once Livewire is idle, the
        // "Confirming…" status is cleared.
        const settle = () => {
            window.clearTimeout( settleTimer );
            settleTimer = window.setTimeout( () => {
                if ( awaitingOutcome && 0 === inFlight && ! leaving && document.contains( statusBox ) ) {
                    stopWaiting();
                    statusBox.textContent = '';
                }
            }, 300 );
        };

        // Removed with the component (Livewire cleans up `$wire.$hook`).
        $wire.$hook( 'request', ( { succeed, fail } ) => {
            inFlight++;
            window.clearTimeout( settleTimer );

            succeed( () => {
                inFlight = Math.max( 0, inFlight - 1 );

                if ( awaitingOutcome ) {
                    settle();
                }
            } );

            // A failed request (an expired page, a server error, no
            // connection) never reaches the checkout.
            fail( () => {
                inFlight = Math.max( 0, inFlight - 1 );

                if ( awaitingOutcome ) {
                    stopWaiting();
                    showFailure( settings.messages.failed );
                }
            } );
        } );

        $wire.on( 'payment-failed', ( event ) => {
            stopWaiting();

            // An ineligible wallet was already explained politely.
            if ( quiet ) {
                quiet = false;

                return;
            }

            showFailure( event?.message );
        } );

        $wire.on( 'paypal-order-changed', ( event ) => {
            settings.config.client_secret    = event.orderId;
            settings.config.options.order_id = event.orderId;
        } );

        import( settings.module )
            .then( ( paypal ) => paypal[ settings.mount ]( container, settings.config, {
                label: settings.label,
                onApprove: async ( data ) => {
                    awaitingOutcome = true;
                    announce( statusBox, settings.messages.confirming );

                    try {
                        await $wire.confirm( data?.orderID ?? null );
                    } catch {
                        if ( awaitingOutcome ) {
                            stopWaiting();
                            showFailure( settings.messages.failed );
                        }

                        return;
                    }

                    if ( awaitingOutcome ) {
                        settle();
                    }
                },
                onCancel: () => {
                    announce( statusBox, settings.messages.canceled );
                    focusHeading();
                },
                onError: () => $wire.fail( settings.messages.failed ),
                onIneligible: () => {
                    container.hidden = true;
                    quiet            = true;
                    announce( statusBox, settings.messages.ineligible );
                    $wire.fail( settings.messages.ineligible );
                },
            } ) )
            .catch( () => announce( errorBox, settings.messages.loadFailed ) )
            .finally( () => {
                loading.hidden = true;
            } );
    </script>
    @endscript
@endif
