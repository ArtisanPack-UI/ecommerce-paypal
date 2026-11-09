<?php

/**
 * Base PayPal response object.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api\Data;

/**
 * A decoded PayPal resource: typed accessors for the fields the package
 * uses, and the full body in {@see self::$raw} for everything else.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
abstract class PayPalData
{
    /**
     * Creates the object.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $raw  Decoded response body.
     */
    final public function __construct( public readonly array $raw )
    {
    }

    /**
     * Builds the object from a decoded body.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $raw  Decoded response body.
     *
     * @return static
     */
    public static function fromArray( array $raw ): static
    {
        return new static( $raw );
    }

    /**
     * The resource id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function id(): string
    {
        return (string) ( $this->raw['id'] ?? '' );
    }

    /**
     * The resource status, upper-cased (`COMPLETED`, `PENDING`, …), or null.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function status(): ?string
    {
        return isset( $this->raw['status'] ) ? strtoupper( (string) $this->raw['status'] ) : null;
    }

    /**
     * A value by dot path, or `$default`.
     *
     * @since 1.0.0
     *
     * @param  string  $path     Dot path (`purchase_units.0.amount.value`).
     * @param  mixed   $default  Fallback.
     *
     * @return mixed
     */
    public function get( string $path, mixed $default = null ): mixed
    {
        return data_get( $this->raw, $path, $default );
    }

    /**
     * The `href` of the HATEOAS link with relation `$rel`, or null.
     *
     * @since 1.0.0
     *
     * @param  string  $rel  Link relation (`approve`, `payer-action`, `up`, …).
     *
     * @return string|null
     */
    public function link( string $rel ): ?string
    {
        foreach ( (array) ( $this->raw['links'] ?? [] ) as $link ) {
            if ( is_array( $link ) && ( $link['rel'] ?? null ) === $rel && isset( $link['href'] ) ) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    /**
     * A PayPal money object at `$path` (`{ currency_code, value }`), or null.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Dot path.
     *
     * @return array{currency_code: string, value: string}|null
     */
    protected function money( string $path ): ?array
    {
        $amount = $this->get( $path );

        if ( ! is_array( $amount ) || ! isset( $amount['currency_code'], $amount['value'] ) ) {
            return null;
        }

        return [ 'currency_code' => (string) $amount['currency_code'], 'value' => (string) $amount['value'] ];
    }

    /**
     * A string at `$path`, or null when missing or empty.
     *
     * @since 1.0.0
     *
     * @param  string  $path  Dot path.
     *
     * @return string|null
     */
    protected function string( string $path ): ?string
    {
        $value = $this->get( $path );

        return is_scalar( $value ) && '' !== (string) $value ? (string) $value : null;
    }
}
