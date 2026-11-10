<?php

/**
 * PayPal package web routes.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Http\Controllers\ApplePayDomainAssociationController;
use ArtisanPackUI\EcommercePayPal\Support\ApplePayDomainAssociation;
use Illuminate\Support\Facades\Route;

// Apple fetches this from the domain root when the domain is registered
// in the PayPal dashboard (spec §9.5). No session or cookies are needed.
Route::get( ApplePayDomainAssociation::PATH, ApplePayDomainAssociationController::class )
    ->name( 'artisanpack.ecommerce-paypal.apple-pay.domain-association' );
