{{--
    Live PayPal Pay Later message. See Livewire\PayLaterMessage.

    The message is mounted once inside `wire:ignore` and updated in place
    when the amount changes. The box keeps a line's height while it loads.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div data-paypal-pay-later-live>
    @if ( null !== $settings )
        <div wire:ignore class="min-h-6" @if ( '' === $value ) hidden @endif data-paypal-pay-later-message data-placement="{{ $settings['placement'] }}"></div>
    @endif
</div>

@if ( null !== $settings )
    @script
    <script>
        const settings = @js( $settings );
        const target   = $wire.$el.querySelector( '[data-paypal-pay-later-message]' );

        // An empty value (the cart emptied) hides the box; the message is
        // mounted on the first amount and updated in place after that.
        let message = null;

        const show = async ( amount ) => {
            target.hidden = ! amount;

            if ( ! amount ) {
                return;
            }

            if ( message ) {
                await message.update( amount );

                return;
            }

            const paypal = await import( @js( $module ) );

            message = await paypal.mountMessages( target, settings.config, { amount, placement: settings.placement } );
        };

        $wire.$watch( 'value', ( amount ) => show( amount ).catch( () => {} ) );
        show( $wire.value ).catch( () => {} );
    </script>
    @endscript
@endif
