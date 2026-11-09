<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the package, and the engine in Testbench.
| Unit tests run without a Laravel application.
|
*/

pest()->extend( Tests\TestCase::class )
    ->in( 'Feature' );
