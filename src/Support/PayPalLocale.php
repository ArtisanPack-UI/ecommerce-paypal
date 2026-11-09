<?php

/**
 * PayPal locale resolution.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Support;

/**
 * Maps an app locale (`en`, `fr_CA`, `de-DE`) to a locale PayPal supports
 * (`en-US`, `fr-CA`, `de-DE`), falling back to `en-US` (spec §13).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalLocale
{
    /**
     * The fallback locale.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FALLBACK = 'en-US';

    /**
     * Locales PayPal's checkout supports.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SUPPORTED = [
        'ar-EG', 'cs-CZ', 'da-DK', 'de-AT', 'de-CH', 'de-DE', 'el-GR', 'en-AU', 'en-CA', 'en-GB',
        'en-IE', 'en-IN', 'en-NZ', 'en-US', 'es-AR', 'es-ES', 'es-MX', 'es-US', 'fi-FI', 'fr-BE',
        'fr-CA', 'fr-CH', 'fr-FR', 'he-IL', 'hu-HU', 'id-ID', 'it-IT', 'ja-JP', 'ko-KR', 'nl-BE',
        'nl-NL', 'no-NO', 'pl-PL', 'pt-BR', 'pt-PT', 'ru-RU', 'sk-SK', 'sv-SE', 'th-TH', 'tr-TR',
        'zh-CN', 'zh-HK', 'zh-TW',
    ];

    /**
     * The default region for a bare language code.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const DEFAULT_REGIONS = [
        'ar' => 'EG', 'cs' => 'CZ', 'da' => 'DK', 'de' => 'DE', 'el' => 'GR', 'en' => 'US',
        'es' => 'ES', 'fi' => 'FI', 'fr' => 'FR', 'he' => 'IL', 'hu' => 'HU', 'id' => 'ID',
        'it' => 'IT', 'ja' => 'JP', 'ko' => 'KR', 'nl' => 'NL', 'no' => 'NO', 'nb' => 'NO',
        'pl' => 'PL', 'pt' => 'BR', 'ru' => 'RU', 'sk' => 'SK', 'sv' => 'SE', 'th' => 'TH',
        'tr' => 'TR', 'zh' => 'CN',
    ];

    /**
     * The PayPal locale for `$locale` (the app locale when null).
     *
     * @since 1.0.0
     *
     * @param  string|null  $locale  App locale.
     *
     * @return string
     */
    public static function resolve( ?string $locale = null ): string
    {
        $locale = str_replace( '_', '-', trim( (string) ( $locale ?? app()->getLocale() ) ) );

        if ( '' === $locale ) {
            return self::FALLBACK;
        }

        $parts    = explode( '-', $locale );
        $language = strtolower( $parts[0] );
        $region   = isset( $parts[1] ) ? strtoupper( $parts[1] ) : null;

        if ( null !== $region && in_array( $language . '-' . $region, self::SUPPORTED, true ) ) {
            return $language . '-' . $region;
        }

        $language = 'nb' === $language ? 'no' : $language;

        if ( isset( self::DEFAULT_REGIONS[ $language ] ) ) {
            return $language . '-' . self::DEFAULT_REGIONS[ $language ];
        }

        return self::FALLBACK;
    }
}
