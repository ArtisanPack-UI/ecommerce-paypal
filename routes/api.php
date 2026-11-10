<?php

/**
 * Saved payment methods REST routes (spec §10.3).
 *
 * Loaded by {@see ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider::registerApiRoutes()}
 * inside the engine's REST group (prefix `api/ecommerce/v1`, name prefix
 * `ecommerce.api.`, the `api.middleware` stack). Like the engine's own
 * `me/*` routes, each needs the API's auth middleware and the
 * `ecommerce.admin.mutate` rate policy; writes also need an
 * `Idempotency-Key`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Http\Controllers\Api\SavedPaymentMethodController;
use Illuminate\Support\Facades\Route;

$auth = (array) config( 'artisanpack.ecommerce.api.auth_middleware', [ 'ecommerce.service-signature', 'auth:sanctum' ] );

Route::where( [ 'token' => '[0-9]+' ] )->group( function () use ( $auth ): void {
    Route::get( 'me/paypal/payment-methods', [ SavedPaymentMethodController::class, 'index' ] )
        ->middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate' ] ) )
        ->name( 'me.paypal.payment-methods.index' );

    Route::middleware( array_merge( $auth, [ 'ecommerce.rate-limit:ecommerce.admin.mutate', 'ecommerce.idempotency' ] ) )->group( function (): void {
        Route::delete( 'me/paypal/payment-methods/{token}', [ SavedPaymentMethodController::class, 'destroy' ] )
            ->name( 'me.paypal.payment-methods.destroy' );

        Route::post( 'me/paypal/payment-methods/{token}/default', [ SavedPaymentMethodController::class, 'makeDefault' ] )
            ->name( 'me.paypal.payment-methods.default' );
    } );
} );
