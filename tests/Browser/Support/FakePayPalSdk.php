<?php

declare( strict_types=1 );

namespace Tests\Browser\Support;

use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use Illuminate\Support\Facades\File;

/**
 * Serves a fake `paypal-checkout.js` to the drivers, so a browser test
 * can play PayPal's part (approve, cancel, report ineligible) without the
 * SDK. See `tests/Browser/workbench/resources/js/fake-paypal-checkout.js`.
 *
 * @since 1.0.0
 */
final class FakePayPalSdk
{
    /**
     * Replaces the published module with the fake. BrowserTestCase puts
     * the real build back before every test.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function install(): void
    {
        $module = public_path( 'vendor/' . EcommercePayPalServiceProvider::VIEW_NAMESPACE . '/paypal-checkout.js' );

        File::ensureDirectoryExists( dirname( $module ) );
        File::copy( dirname( __DIR__ ) . '/workbench/resources/js/fake-paypal-checkout.js', $module );
    }

    /**
     * A script that resolves with `$condition` once it holds, or false
     * after five seconds. Waiting on real state keeps a test from
     * asserting before Livewire has answered, without a fixed sleep.
     *
     * @since 1.0.0
     *
     * @param  string  $condition  JavaScript expression.
     *
     * @return string
     */
    public static function waitFor( string $condition ): string
    {
        return <<<JS
            async () => {
                for ( let attempt = 0; attempt < 50; attempt++ ) {
                    const result = ( () => ( {$condition} ) )();

                    if ( result ) {
                        return result;
                    }

                    await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
                }

                return false;
            }
            JS;
    }
}
