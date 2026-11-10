{{--
    The PayPal buttons driver (PayPal, Pay Later, Venmo). See PayPalButtons.

    The buttons sit in `wire:ignore` so Livewire never re-renders PayPal's
    iframes; the script below mounts them once per component.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div class="flex flex-col gap-3" data-payment-driver="paypal-buttons">
    @if ( $available )
        <div wire:ignore class="flex flex-col gap-3">
            @if ( $restart )
                <x-artisanpack-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-base-content!" :title="__( 'PayPal declined that payment method. Choose another one in PayPal to finish your order.' )" data-paypal-restart />
            @endif

            <p class="text-sm text-base-content/70" data-paypal-loading>{{ __( 'Loading PayPal…' ) }}</p>

            <div role="group" aria-label="{{ __( 'Pay with PayPal' ) }}" data-paypal-buttons></div>

            <p class="text-sm text-base-content/70 empty:hidden" role="status" aria-live="polite" data-paypal-status></p>

            <p class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" role="alert" aria-live="assertive" data-paypal-error></p>

            <p class="text-xs text-base-content/70">{{ __( 'You\'ll finish paying in a PayPal window. This store never sees your PayPal login or card details.' ) }}</p>
        </div>

        @if ( $canSave )
            @include( 'ecommerce-paypal::livewire.payment.partials.save-method', [ 'label' => __( 'Save PayPal for faster checkout' ), 'hint' => __( 'Next time, PayPal shows your saved account.' ) ] )
        @endif
    @else
        <x-artisanpack-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-base-content!" :title="__( 'This payment method isn\'t available.' )" data-payment-unavailable />
    @endif
</div>

@if ( $available )
    @script
    <script>
        const root      = $wire.$el;
        const settings  = @js( $paypal );
        const container = root.querySelector( '[data-paypal-buttons]' );
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

        // When PayPal's window closes it can leave focus on the page body:
        // move it to the step heading. A failure that arrives while the
        // shopper is on another control (a failed "save" update, say)
        // leaves their focus alone. Either way the error is announced.
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

            // An ineligible PayPal was already explained politely.
            if ( quiet ) {
                quiet = false;

                return;
            }

            showFailure( event?.message );
        } );

        // The shopper's "save" choice replaced the Order; the SDK's
        // createOrder reads the id from the config when PayPal opens.
        $wire.on( 'paypal-order-changed', ( event ) => {
            settings.config.client_secret    = event.orderId;
            settings.config.options.order_id = event.orderId;
        } );

        import( settings.module )
            .then( ( paypal ) => paypal.mountButtons( container, settings.config, {
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
                    quiet = true;
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
