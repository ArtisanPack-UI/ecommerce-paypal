{{--
    The PayPal card fields driver. See PayPalCardFields.

    The fields are PayPal-hosted iframes inside `wire:ignore`: card data
    never reaches Livewire. Each field has a visible label (the iframe is
    named by `aria-labelledby` on its group) and an inline error.

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
                        <p id="paypal-card-{{ $field }}-error-{{ $this->getId() }}" class="text-sm text-[color-mix(in_oklab,var(--color-error)_55%,var(--color-base-content))] empty:hidden" data-paypal-card-error="{{ $field }}"></p>
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

        $wire.on( 'payment-failed', ( event ) => {
            setProcessing( false );

            // A saved-card failure is announced next to its button; the
            // fields' own region is hidden while a saved card is chosen.
            const savedBox = root.querySelector( '[data-paypal-saved-card-error]' );

            if ( savedBox && null !== savedBox.offsetParent ) {
                savedBox.textContent = '';
                window.requestAnimationFrame( () => {
                    savedBox.textContent = event?.message || settings.messages.failed;
                } );

                return;
            }

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
                announce( errorBox, settings.messages.invalid );
                fields[ invalid[ 0 ] ]?.querySelector( 'iframe' )?.focus();

                return;
            }

            setProcessing( true );
            announce( statusBox, settings.messages.processing );

            try {
                await card.submit();
            } catch {
                setProcessing( false );
                announce( errorBox, settings.messages.failed );
            }
        } );

        import( settings.module )
            .then( ( paypal ) => paypal.mountCardFields( fields, settings.config, {
                onApprove: async ( data ) => {
                    await $wire.confirm( data?.orderID ?? null );
                },
                onError: () => {
                    setProcessing( false );
                    $wire.fail( '' );
                },
                onIneligible: () => {
                    form.hidden = true;
                    announce( errorBox, settings.messages.ineligible );
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
