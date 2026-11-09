<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Support\PayPalLocale;

it( 'resolves app locales to PayPal locales', function ( string $locale, string $expected ): void {
    expect( PayPalLocale::resolve( $locale ) )->toBe( $expected );
} )->with( [
    'supported pair'        => [ 'fr-CA', 'fr-CA' ],
    'underscored pair'      => [ 'de_AT', 'de-AT' ],
    'language only'         => [ 'de', 'de-DE' ],
    'unsupported region'    => [ 'es-CL', 'es-ES' ],
    'script and region'     => [ 'zh-Hant-TW', 'zh-TW' ],
    'underscored script'    => [ 'zh_Hant_TW', 'zh-TW' ],
    'script only'           => [ 'zh-Hans', 'zh-CN' ],
    'numeric region'        => [ 'es-419', 'es-ES' ],
    'Norwegian Bokmål'      => [ 'nb-NO', 'no-NO' ],
    'unknown language'      => [ 'xx', 'en-US' ],
    'empty'                 => [ '', 'en-US' ],
] );
