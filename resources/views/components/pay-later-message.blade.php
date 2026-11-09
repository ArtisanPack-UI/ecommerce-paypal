{{--
    PayPal Pay Later message. See View\Components\PayLaterMessage.

    The box keeps a line's height while PayPal's message loads, so nothing
    below it moves. PayPal renders its own accessible markup inside it.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
<div {{ $attributes->class( [ 'min-h-6' ] ) }} id="{{ $id }}" data-paypal-pay-later-message data-placement="{{ $settings['placement'] }}"></div>

<script type="module">
    {{-- Loaded through the shared module, so the SDK loads once per page. --}}
    import( @js( $module ) )
        .then( ( paypal ) => paypal.mountMessages( document.getElementById( @js( $id ) ), @js( $settings['config'] ), {
            amount: @js( $settings['amount'] ),
            placement: @js( $settings['placement'] ),
        } ) )
        .catch( () => {} );
</script>
