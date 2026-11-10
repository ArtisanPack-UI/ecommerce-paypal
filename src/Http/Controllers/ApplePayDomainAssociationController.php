<?php

/**
 * Serves the Apple Pay domain association file.
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

use ArtisanPackUI\EcommercePayPal\Support\ApplePayDomainAssociation;
use Illuminate\Http\Response;

/**
 * `GET /.well-known/apple-developer-merchantid-domain-association`
 * (spec §9.5): the file Apple checks when the store's domain is registered
 * for Apple Pay in the PayPal dashboard. A `404` when none is configured.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class ApplePayDomainAssociationController
{
    /**
     * Serves the file as plain text.
     *
     * @since 1.0.0
     *
     * @param  ApplePayDomainAssociation  $association  Resolver.
     *
     * @return Response
     */
    public function __invoke( ApplePayDomainAssociation $association ): Response
    {
        $contents = $association->contents();

        abort_if( null === $contents, 404, '', [ 'X-Content-Type-Options' => 'nosniff' ] );

        return new Response( $contents, 200, [
            'Content-Type'           => 'text/plain; charset=UTF-8',
            'Cache-Control'          => 'public, max-age=' . ApplePayDomainAssociation::CACHE_SECONDS,
            'X-Content-Type-Options' => 'nosniff',
        ] );
    }
}
