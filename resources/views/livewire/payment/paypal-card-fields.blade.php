{{--
    The PayPal card fields driver. See PayPalCardFields.

    The fields are PayPal-hosted iframes inside `wire:ignore`: card data
    never reaches Livewire. Each field has a visible label (the iframe is
    named by `aria-labelledby` on its group) and an inline error, announced
    politely. "Pay now" with invalid details names the fields to check.

    A signed-in shopper's saved cards are listed above the fields with "Use
    a new card". While a saved card is chosen the fields stay mounted but
    hidden, so switching back doesn't reload PayPal.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div class="flex flex-col gap-3" data-payment-driver="paypal-card-fields">
    @if ( $available )
        @if ( [] !== $savedCards )
            <div class="flex flex-col gap-3" data-paypal-saved-cards>
                <x-artisanpack-radio
                    id="paypal-saved-card-{{ $this->getId() }}"
                    :label="__( 'Pay with' )"
                    :options="$savedCards"
                    wire:model.live="savedCard"
                    wire:loading.attr="disabled"
                    wire:target="savedCard, payWithSavedCard"
                    data-paypal-saved-card-choice
                />

                @if ( $usingSaved )
                    <x-artisanpack-button
                        type="button"
                        color="primary"
                        class="w-full"
                        wire:click="payWithSavedCard"
                        wire:loading.attr="disabled"
                        wire:target="savedCard, payWithSavedCard"
                        spinner="payWithSavedCard"
                        data-paypal-saved-card-pay
                    >
                        {{ __( 'Pay with this card' ) }}
                    </x-artisanpack-button>

                    <p class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" role="alert" aria-live="assertive" data-paypal-saved-card-error wire:ignore></p>
                @endif
            </div>
        @endif

        <div @class( [ 'hidden' => $usingSaved ] ) data-paypal-new-card>
        <div wire:ignore class="flex flex-col gap-3">
            <p class="text-sm text-base-content/70" data-paypal-loading>{{ __( 'Loading the card form…' ) }}</p>

            <div class="flex flex-col gap-3" data-paypal-card-form>
                @foreach ( $fields as $field => $label )
                    <div class="flex flex-col gap-1">
                        <span id="paypal-card-{{ $field }}-label-{{ $this->getId() }}" class="text-sm font-medium">{{ $label }}</span>
                        <div
                            role="group"
                            aria-labelledby="paypal-card-{{ $field }}-label-{{ $this->getId() }}"
                            aria-describedby="paypal-card-{{ $field }}-error-{{ $this->getId() }}"
                            class="min-h-12 data-[invalid=true]:outline-2 data-[invalid=true]:outline-error rounded-field"
                            data-paypal-card-field="{{ $field }}"
                        ></div>
                        <p id="paypal-card-{{ $field }}-error-{{ $this->getId() }}" class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" aria-live="polite" data-paypal-card-error="{{ $field }}"></p>
                    </div>
                @endforeach

                <x-artisanpack-button type="button" color="primary" class="w-full" disabled data-paypal-card-submit>
                    {{ __( 'Pay now' ) }}
                </x-artisanpack-button>
            </div>

            <p class="text-sm text-base-content/70 empty:hidden" role="status" aria-live="polite" data-paypal-status></p>

            <p class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" role="alert" aria-live="assertive" data-paypal-error></p>

            <p class="text-xs text-base-content/70">{{ __( 'Your card details go straight to PayPal. This store never sees them.' ) }}</p>
        </div>
        </div>

        @if ( $canSave && ! $usingSaved )
            @include( 'ecommerce-paypal::livewire.payment.partials.save-method', [ 'label' => __( 'Save this card for faster checkout' ), 'hint' => __( 'PayPal keeps the card. This store only keeps its brand and last four digits.' ) ] )
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
        const form      = root.querySelector( '[data-paypal-card-form]' );
        const loading   = root.querySelector( '[data-paypal-loading]' );
        const statusBox = root.querySelector( '[data-paypal-status]' );
        const errorBox  = root.querySelector( '[data-paypal-error]' );
        const submit    = root.querySelector( '[data-paypal-card-submit]' );
        const fields    = {};
        const errors    = {};
        const stateKeys = { name: 'cardNameField', number: 'cardNumberField', expiry: 'cardExpiryField', cvv: 'cardCvvField' };
        let card        = null;
        let processing  = false;
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

        root.querySelectorAll( '[data-paypal-card-field]' ).forEach( ( element ) => {
            fields[ element.dataset.paypalCardField ] = element;
        } );

        root.querySelectorAll( '[data-paypal-card-error]' ).forEach( ( element ) => {
            errors[ element.dataset.paypalCardError ] = element;
        } );

        // Clears first so the same message is announced again.
        const announce = ( box, message ) => {
            statusBox.textContent = '';
            errorBox.textContent  = '';
            window.requestAnimationFrame( () => {
                box.textContent = message;
            } );
        };

        const setProcessing = ( on ) => {
            processing      = on;
            submit.disabled = on || null === card;
            submit.setAttribute( 'aria-busy', on ? 'true' : 'false' );
        };

        const showError = ( field, invalid ) => {
            if ( ! fields[ field ] ) {
                return;
            }

            fields[ field ].dataset.invalid = invalid ? 'true' : 'false';
            errors[ field ].textContent     = invalid ? settings.messages.fields[ field ] : '';
        };

        const fieldState = ( state, field ) => state?.fields?.[ stateKeys[ field ] ];

        // "Check these card details: Card number and Security code." The
        // fields are named in words, so the summary doesn't rely on the
        // outline color alone.
        const invalidMessage = ( invalid ) => {
            const labels = ( 0 === invalid.length ? Object.keys( fields ) : invalid ).map( ( field ) => settings.labels[ field ] ).filter( Boolean );
            let list     = labels.join( ', ' );

            try {
                list = new Intl.ListFormat( document.documentElement.lang || undefined, { type: 'conjunction' } ).format( labels );
            } catch {
                // An unknown page language keeps the comma-separated list.
            }

            return settings.messages.invalid.replace( ':fields', list );
        };

        // After a failure (including PayPal's 3-D Secure window closing),
        // focus goes back to the button the shopper pressed, but only when
        // it was lost (on the page body, or on an element that has left the
        // page). Focus the shopper moved to another control stays there.
        const restoreFocus = ( button ) => {
            const active = document.activeElement;

            if ( button && ( ! active || active === document.body || ! active.isConnected ) ) {
                button.focus();
            }
        };

        // Gives "Pay now" back once the outcome of an approved payment is
        // known, or can no longer arrive.
        const reset = () => {
            awaitingOutcome = false;
            window.clearTimeout( settleTimer );
            setProcessing( false );
            restoreFocus( submit );
        };

        // An approved payment either leaves the page, re-renders this
        // driver, or fails with `payment-failed`. When the checkout keeps
        // the shopper here instead (it couldn't place the order and shows
        // why), nothing reports back: once Livewire is idle, "Pay now" is
        // enabled again and the status cleared.
        const settle = () => {
            window.clearTimeout( settleTimer );
            settleTimer = window.setTimeout( () => {
                if ( awaitingOutcome && 0 === inFlight && ! leaving && document.contains( submit ) ) {
                    reset();
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
                    reset();
                    announce( errorBox, settings.messages.failed );
                }
            } );
        } );

        $wire.on( 'payment-failed', ( event ) => {
            awaitingOutcome = false;
            window.clearTimeout( settleTimer );
            setProcessing( false );

            // Ineligible card fields were already explained politely.
            if ( quiet ) {
                quiet = false;

                return;
            }

            // A saved-card failure is announced next to its button; the
            // fields' own region is hidden while a saved card is chosen.
            const savedBox = root.querySelector( '[data-paypal-saved-card-error]' );

            if ( savedBox && null !== savedBox.offsetParent ) {
                restoreFocus( root.querySelector( '[data-paypal-saved-card-pay]' ) );
                savedBox.textContent = '';
                window.requestAnimationFrame( () => {
                    savedBox.textContent = event?.message || settings.messages.failed;
                } );

                return;
            }

            restoreFocus( submit );
            announce( errorBox, event?.message || settings.messages.failed );
        } );

        $wire.on( 'paypal-order-changed', ( event ) => {
            settings.config.client_secret    = event.orderId;
            settings.config.options.order_id = event.orderId;
        } );

        submit.addEventListener( 'click', async () => {
            if ( processing || null === card ) {
                return;
            }

            const state   = await card.getState().catch( () => undefined );
            const invalid = Object.keys( fields ).filter( ( field ) => fieldState( state, field ) && ! fieldState( state, field ).isValid );

            if ( state && false === state.isFormValid ) {
                invalid.forEach( ( field ) => showError( field, true ) );
                announce( errorBox, invalidMessage( invalid ) );
                fields[ invalid[ 0 ] ]?.querySelector( 'iframe' )?.focus();

                return;
            }

            setProcessing( true );
            announce( statusBox, settings.messages.processing );

            try {
                await card.submit();
            } catch {
                setProcessing( false );
                restoreFocus( submit );
                announce( errorBox, settings.messages.failed );
            }
        } );

        import( settings.module )
            .then( ( paypal ) => paypal.mountCardFields( fields, settings.config, {
                onApprove: async ( data ) => {
                    awaitingOutcome = true;

                    try {
                        await $wire.confirm( data?.orderID ?? null );
                    } catch {
                        if ( awaitingOutcome ) {
                            reset();
                            announce( errorBox, settings.messages.failed );
                        }

                        return;
                    }

                    if ( awaitingOutcome ) {
                        settle();
                    }
                },
                onError: () => {
                    setProcessing( false );
                    $wire.fail( settings.messages.failed );
                },
                onIneligible: () => {
                    form.hidden = true;
                    quiet       = true;
                    announce( statusBox, settings.messages.ineligible );
                    $wire.fail( settings.messages.ineligible );
                },
                onChange: ( state ) => {
                    Object.keys( fields ).forEach( ( field ) => {
                        if ( fieldState( state, field )?.isPotentiallyValid ) {
                            showError( field, false );
                        }
                    } );
                },
                onBlur: ( state ) => {
                    const field   = state?.emittedBy;
                    const current = fieldState( state, field );

                    if ( current ) {
                        showError( field, ! current.isEmpty && ! current.isValid );
                    }
                },
            } ) )
            .then( ( mounted ) => {
                if ( mounted?.eligible ) {
                    card = mounted;
                    setProcessing( false );
                }
            } )
            .catch( () => {
                form.hidden = true;
                announce( errorBox, settings.messages.loadFailed );
            } )
            .finally( () => {
                loading.hidden = true;
            } );
    </script>
    @endscript
@endif
