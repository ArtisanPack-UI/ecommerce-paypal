<?php

declare( strict_types=1 );

/*
| The day-1 catalogues (spec §13). CI's translation lint
| (`ecommerce:lint:translations`) fails on a string in src/ or resources/
| missing from any of them; these checks keep each translation usable: it
| exists, isn't blank, and keeps the original's placeholders and plural
| forms, so `:date` or `:count` is never silently dropped.
*/

const PAYPAL_LANG_DIR = __DIR__ . '/../../lang';

const PAYPAL_TRANSLATED_LOCALES = [ 'es', 'fr', 'de' ];

/**
 * A locale's catalogue.
 *
 * @param  string  $locale  Locale.
 *
 * @return array<string, string>
 */
function paypalCatalogue( string $locale ): array
{
    return json_decode( (string) file_get_contents( PAYPAL_LANG_DIR . '/' . $locale . '.json' ), true, 512, JSON_THROW_ON_ERROR );
}

/**
 * The sorted, unique `:placeholders` in `$string`.
 *
 * @param  string  $string  String.
 *
 * @return array<int, string>
 */
function paypalPlaceholders( string $string ): array
{
    preg_match_all( '/:[A-Za-z_]+/', $string, $matches );

    $placeholders = array_values( array_unique( $matches[0] ) );
    sort( $placeholders );

    return $placeholders;
}

it( 'keeps the English catalogue as the source of every key', function (): void {
    $english = paypalCatalogue( 'en' );

    expect( $english )->not->toBeEmpty();

    foreach ( $english as $key => $value ) {
        expect( $value )->toBe( $key, 'en.json must map each key to itself' );
    }
} );

it( 'translates every key, and nothing else', function ( string $locale ): void {
    $english    = array_keys( paypalCatalogue( 'en' ) );
    $translated = array_keys( paypalCatalogue( $locale ) );

    expect( array_values( array_diff( $english, $translated ) ) )->toBe( [], $locale . '.json is missing keys' )
        ->and( array_values( array_diff( $translated, $english ) ) )->toBe( [], $locale . '.json has keys en.json does not' );
} )->with( PAYPAL_TRANSLATED_LOCALES );

it( 'keeps every placeholder and plural form', function ( string $locale ): void {
    foreach ( paypalCatalogue( $locale ) as $key => $value ) {
        expect( trim( $value ) )->not->toBe( '', $locale . ': blank translation for "' . $key . '"' )
            ->and( paypalPlaceholders( $value ) )->toBe( paypalPlaceholders( $key ), $locale . ': placeholders differ for "' . $key . '"' )
            ->and( substr_count( $value, '|' ) )->toBe( substr_count( $key, '|' ), $locale . ': plural forms differ for "' . $key . '"' );
    }
} )->with( PAYPAL_TRANSLATED_LOCALES );
