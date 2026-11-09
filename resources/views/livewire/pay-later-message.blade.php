{{--
    Live PayPal Pay Later message. See Livewire\PayLaterMessage.

    The box is rendered whenever messages may show here; an empty value
    only hides it. The script looks the box (and its settings) up on every
    change, so a box that first appears after mount, when the shopper's
    first cart is created, is mounted too. Once mounted, the message is
    updated in place. The box keeps a line's height while it loads.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div data-paypal-pay-later-live>
    @if ( null !== $settings )
        <div
            wire:ignore
            class="min-h-6"
            @if ( '' === $value ) hidden @endif
            data-paypal-pay-later-message
            data-placement="{{ $settings['placement'] }}"
            data-paypal-settings="{{ json_encode( $settings['config'] ) }}"
        ></div>
    @endif
</div>

@script
<script>
    const root   = $wire.$el;
    const module = @js( $module );

    let message = null;
    let mounted = null;

    const show = async ( amount ) => {
        const target = root.querySelector( '[data-paypal-pay-later-message]' );

        if ( ! target ) {
            return;
        }

        target.hidden = ! amount;

        if ( ! amount ) {
            return;
        }

        if ( message && mounted === target ) {
            await message.update( amount );

            return;
        }

        const paypal = await import( module );

        mounted = target;
        message = await paypal.mountMessages( target, JSON.parse( target.dataset.paypalSettings ), {
            amount,
            placement: target.dataset.placement,
        } );
    };

    // Runs after Livewire has morphed the new render into the page.
    $wire.$watch( 'value', ( amount ) => window.requestAnimationFrame( () => show( amount ).catch( () => {} ) ) );
    show( $wire.value ).catch( () => {} );
</script>
@endscript
