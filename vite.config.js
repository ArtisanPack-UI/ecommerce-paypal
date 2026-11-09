import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// The shared PayPal checkout module, built as one ES module to dist/ (spec
// §9.1). dist/ is committed and published to public/vendor/ecommerce-paypal.
export default defineConfig( {
    build: {
        lib: {
            entry: resolve( import.meta.dirname, 'resources/js/paypal-checkout.js' ),
            formats: [ 'es' ],
            fileName: () => 'paypal-checkout.js',
        },
        outDir: 'dist',
        emptyOutDir: true,
        minify: false,
        sourcemap: false,
    },
    test: {
        environment: 'jsdom',
        include: [ 'tests/js/**/*.test.js' ],
    },
} );
