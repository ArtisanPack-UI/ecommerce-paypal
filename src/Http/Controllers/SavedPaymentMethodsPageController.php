<?php

/**
 * Saved payment methods account page controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Http\Controllers;

use ArtisanPackUI\EcommerceStorefrontLivewire\EcommerceStorefrontLivewireServiceProvider;
use ArtisanPackUI\EcommerceStorefrontLivewire\Support\StorefrontSeo;
use Illuminate\Http\Response;

/**
 * Serves `account/payment-methods` (spec §10.3) in the storefront's layout,
 * kept private like the storefront's own account pages: not cached, not
 * indexed, and not leaking its URL in the `Referer`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class SavedPaymentMethodsPageController
{
    /**
     * Renders the page.
     *
     * @since 1.0.0
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $seo    = app( StorefrontSeo::class );
        $layout = config( 'artisanpack.ecommerce-storefront-livewire.storefront.layout' );

        $seo->noindex( 'account' );

        $response = response()->view( 'ecommerce-paypal::pages.account.saved-methods', [
            'ecommerceStorefrontLayout' => is_string( $layout ) && '' !== $layout ? $layout : EcommerceStorefrontLivewireServiceProvider::DEFAULT_LAYOUT,
            'ecommerceSeo'              => $seo,
        ] );

        $response->headers->set( 'Cache-Control', 'no-store, private' );
        $response->headers->set( 'X-Robots-Tag', StorefrontSeo::NOINDEX );
        $response->headers->set( 'Referrer-Policy', 'same-origin' );

        return $response;
    }
}
