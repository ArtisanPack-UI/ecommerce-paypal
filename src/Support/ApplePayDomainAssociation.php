<?php

/**
 * Apple Pay domain association file.
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

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Resolves the Apple Pay domain association file the store serves at
 * {@see self::PATH} (spec §9.5).
 *
 * `methods.apple_pay.domain_association` holds one of:
 *
 * - the file's contents;
 * - an absolute path to the file, which must be named
 *   `apple-developer-merchantid-domain-association` (so the setting can't
 *   be pointed at any other file on the server);
 * - an HTTPS URL to fetch it from (cached for an hour).
 *
 * When the setting is empty, the file published to
 * {@see self::publishedPath()} is used.
 *
 * A file or URL is only served when it holds what Apple's file holds: one
 * run of hex. The route is public, so this keeps a mistaken (or malicious)
 * setting from publishing another file or an internal service's answer.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class ApplePayDomainAssociation
{
    /**
     * Where Apple looks for the file.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PATH = '/.well-known/apple-developer-merchantid-domain-association';

    /**
     * The file name Apple uses (and a configured path must end in).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const FILE_NAME = 'apple-developer-merchantid-domain-association';

    /**
     * How long a fetched file is cached, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CACHE_SECONDS = 3600;

    /**
     * How long a failed fetch is remembered, in seconds, so the public
     * route doesn't call a down or wrong URL on every request.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const FAILURE_CACHE_SECONDS = 60;

    /**
     * Creates the resolver.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory  $http  HTTP client, for a URL source.
     */
    public function __construct( protected HttpFactory $http )
    {
    }

    /**
     * Where `ecommerce-paypal:install` users can place the file.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function publishedPath(): string
    {
        return storage_path( 'app/ecommerce-paypal/' . self::FILE_NAME );
    }

    /**
     * What the setting holds: `contents`, `file`, `url`, or `none` (empty,
     * or a path or URL that can't be used).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function source(): string
    {
        $value = self::configured();

        if ( '' === $value ) {
            return is_file( self::publishedPath() ) ? 'file' : 'none';
        }

        if ( str_starts_with( strtolower( $value ), 'https://' ) ) {
            return 'url';
        }

        if ( self::looksLikePath( $value ) ) {
            return self::FILE_NAME === basename( $value ) && is_file( $value ) ? 'file' : 'none';
        }

        return str_starts_with( strtolower( $value ), 'http://' ) ? 'none' : 'contents';
    }

    /**
     * The file's contents, or null when nothing usable is configured or the
     * URL can't be fetched.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function contents(): ?string
    {
        $value = self::configured();

        $source   = self::source();
        $contents = match ( $source ) {
            'contents' => $value,
            'file'     => $this->readFile( '' === $value ? self::publishedPath() : $value ),
            'url'      => $this->fetch( $value ),
            default    => null,
        };
        $contents = null === $contents ? '' : trim( $contents );

        if ( '' === $contents || ( 'contents' !== $source && ! self::isAssociation( $contents ) ) ) {
            return null;
        }

        return $contents;
    }

    /**
     * Whether `$contents` looks like Apple's domain association file (hex,
     * with line breaks allowed).
     *
     * @since 1.0.0
     *
     * @param  string  $contents  Contents.
     *
     * @return bool
     */
    public static function isAssociation( string $contents ): bool
    {
        return 1 === preg_match( '/\A[0-9A-Fa-f\s]+\z/', $contents ) && 1 === preg_match( '/[0-9A-Fa-f]{32}/', $contents );
    }

    /**
     * The trimmed setting.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function configured(): string
    {
        return trim( (string) config( 'artisanpack.ecommerce-paypal.methods.apple_pay.domain_association', '' ) );
    }

    /**
     * Whether `$value` is a filesystem path rather than the file's contents
     * (Apple's contents are a single run of hex without slashes).
     *
     * @since 1.0.0
     *
     * @param  string  $value  Setting.
     *
     * @return bool
     */
    protected static function looksLikePath( string $value ): bool
    {
        return str_starts_with( $value, '/' ) || 1 === preg_match( '#^[A-Za-z]:[\\\\/]#', $value ) || str_ends_with( $value, self::FILE_NAME );
    }

    /**
     * Reads a file, or null.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Path.
     *
     * @return string|null
     */
    protected function readFile( string $path ): ?string
    {
        $contents = is_readable( $path ) ? file_get_contents( $path ) : false;

        return false === $contents ? null : $contents;
    }

    /**
     * Fetches the file from `$url`, caching a successful answer for an hour
     * and a failure for a minute.
     *
     * @since 1.0.0
     *
     * @param  string  $url  HTTPS URL.
     *
     * @return string|null
     */
    protected function fetch( string $url ): ?string
    {
        $key    = 'ecommerce-paypal:apple-pay-domain-association:' . hash( 'sha256', $url );
        $cached = Cache::get( $key );

        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        if ( Cache::has( $key . ':failed' ) ) {
            return null;
        }

        try {
            $response = $this->http->timeout( 5 )->connectTimeout( 3 )->withoutRedirecting()->get( $url );
            $body     = trim( $response->body() );
            $usable   = $response->successful() && self::isAssociation( $body );
        } catch ( Throwable ) {
            $usable = false;
        }

        if ( ! $usable ) {
            Cache::put( $key . ':failed', true, self::FAILURE_CACHE_SECONDS );

            return null;
        }

        Cache::put( $key, $body, self::CACHE_SECONDS );

        return $body;
    }
}
