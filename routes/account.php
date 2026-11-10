<?php

/**
 * Storefront account route for saved payment methods (spec §10.3).
 *
 * Loaded only when storefront-livewire is installed with its routes on.
 * The path sits under the storefront's `account.route_prefix` and runs its
 * `account.middleware` (`web`, `auth` by default), like the storefront's
 * own account pages.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Http\Controllers\SavedPaymentMethodsPageController;
use Illuminate\Support\Facades\Route;

Route::prefix( trim( (string) config( 'artisanpack.ecommerce-storefront-livewire.account.route_prefix', 'account' ), '/' ) )
    ->middleware( (array) config( 'artisanpack.ecommerce-storefront-livewire.account.middleware', [ 'web', 'auth' ] ) )
    ->name( 'artisanpack.ecommerce.account.' )
    ->group( function (): void {
        Route::get( 'payment-methods', SavedPaymentMethodsPageController::class )->name( 'paypal-payment-methods' );
    } );
