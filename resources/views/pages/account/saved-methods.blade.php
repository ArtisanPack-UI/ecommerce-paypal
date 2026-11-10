{{--
    The saved payment methods account page (spec §10.3), in the storefront's
    layout and account shell.

    @package    ArtisanPack_UI
    @subpackage EcommercePayPal

    @since      1.0.0
--}}
@extends( $ecommerceStorefrontLayout )

@push( 'head' )
    @include( 'ecommerce-storefront::partials.seo' )
@endpush

@section( 'title', $ecommerceSeo->title( __( 'Saved payment methods' ) ) )

@section( 'content' )
    <x-artisanpack-ec-account-shell :title="__( 'Saved payment methods' )">
        <livewire:artisanpack-ecommerce-paypal-saved-methods />
    </x-artisanpack-ec-account-shell>
@endsection
