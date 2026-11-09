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

        // Clears first so the same message is announced again.
        const announce = ( box, message ) => {
            statusBox.textContent = '';
            errorBox.textContent  = '';
            window.requestAnimationFrame( () => {
                box.textContent = message;
            } );
        };

        const focusHeading = () => document.getElementById( settings.heading )?.focus();

        $wire.on( 'payment-failed', ( event ) => {
            // An ineligible wallet was already explained politely.
            if ( quiet ) {
                quiet = false;

                return;
            }

            announce( errorBox, event?.message || settings.messages.failed );
        } );

        $wire.on( 'paypal-order-changed', ( event ) => {
            settings.config.client_secret    = event.orderId;
            settings.config.options.order_id = event.orderId;
        } );

        import( settings.module )
            .then( ( paypal ) => paypal[ settings.mount ]( container, settings.config, {
                label: settings.label,
                onApprove: async ( data ) => {
                    announce( statusBox, settings.messages.confirming );
                    await $wire.confirm( data?.orderID ?? null );
                },
                onCancel: () => {
                    announce( statusBox, settings.messages.canceled );
                    focusHeading();
                },
                onError: () => $wire.fail( '' ),
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
