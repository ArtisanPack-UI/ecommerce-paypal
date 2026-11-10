<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the package, and the engine in Testbench.
| Unit tests run without a Laravel application.
|
| The sandbox suite (tests/Sandbox, group `sandbox`) runs against the real
| PayPal sandbox and is skipped without PAYPAL_SANDBOX_CLIENT_ID and
| PAYPAL_SANDBOX_CLIENT_SECRET. `composer test` leaves it out; run it with
| `composer test:sandbox`.
|
*/

pest()->extend( Tests\TestCase::class )
    ->in( 'Feature' );

pest()->extend( Tests\TestCase::class )
    ->use( RefreshDatabase::class )
    ->group( 'sandbox' )
    ->in( 'Sandbox' );

/*
| Browser tests (Pest 4 browser plugin, tests/Browser, group `browser`) run
| the package's pages in Chromium with the stylesheet built by `npm run
| build:browser`. The journeys in tests/Browser/Sandbox also carry the
| `sandbox` group: they drive PayPal's sandbox and are skipped without its
| credentials. `composer test` leaves the Browser suite out, since loading
| it starts Playwright; run it with `composer test:browser`.
*/

pest()->extend( Tests\BrowserTestCase::class )
    ->use( RefreshDatabase::class )
    ->group( 'browser' )
    ->in( 'Browser' );

pest()->browser()->timeout( 15_000 );
