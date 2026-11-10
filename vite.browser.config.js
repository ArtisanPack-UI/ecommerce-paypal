/**
 * Builds the stylesheet the browser tests load (tests/Browser).
 *
 * The package ships no CSS: a host compiles the driver views with its own
 * Tailwind setup (the install command prints the `@source` line). The
 * browser suite stands in for that host, so it builds a small Tailwind +
 * daisyUI bundle into the Testbench public directory the tests point at.
 * The shared checkout module (dist/paypal-checkout.js) is copied next to
 * it by the browser test case, where `ecommerce-paypal:install` would
 * publish it.
 *
 * daisyUI is pinned to ~5.0, as in the storefront's harness: later 5.x
 * releases hide `.tab-content` (ArtisanPack-UI/livewire-ui-components#119).
 */
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig( {
    plugins: [
        laravel( {
            input: [ 'tests/Browser/workbench/resources/css/app.css' ],
            publicDirectory: 'tests/Browser/workbench/public',
            buildDirectory: 'build',
            refresh: false,
        } ),
        tailwindcss(),
    ],
} );
