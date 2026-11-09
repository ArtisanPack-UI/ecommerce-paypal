<?php

/**
 * Redacts secrets and personal data from PayPal payloads.
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
 * Masks what must never reach a log: credentials and tokens, card display
 * data, and the payer's personal data (spec §11).
 *
 * Keys are matched case-insensitively anywhere in the payload. `name` is
 * only masked inside a card or a payer / shipping object, so line item
 * names stay readable.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalRedactor
{
    /**
     * The replacement value.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const MASK = '[redacted]';

    /**
     * Keys masked wherever they appear.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const KEYS = [
        // Credentials and tokens.
        'authorization',
        'client_secret',
        'access_token',
        'refresh_token',
        'id_token',
        'nonce',
        // Card data.
        'number',
        'security_code',
        'last_digits',
        'expiry',
        'brand',
        'bin',
        // Saved payment methods.
        'vault_id',
        // Payer personal data.
        'email_address',
        'email',
        'given_name',
        'surname',
        'full_name',
        'address',
        'address_line_1',
        'address_line_2',
        'admin_area_1',
        'admin_area_2',
        'postal_code',
        'phone',
        'phone_number',
        'national_number',
        'birth_date',
        'tax_info',
        'tax_id',
    ];

    /**
     * Parents whose `name` key is personal data rather than a product name.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const NAME_PARENTS = [ 'card', 'payer', 'shipping', 'apple_pay', 'google_pay', 'venmo', 'paypal' ];

    /**
     * Path segments followed by a saved-payment token id.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TOKEN_PATHS = [ 'payment-tokens', 'setup-tokens' ];

    /**
     * Header names masked in logged headers.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const HEADERS = [ 'authorization', 'paypal-auth-assertion', 'cookie', 'set-cookie' ];

    /**
     * Masks the sensitive values in a payload.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $payload  Decoded payload.
     * @param  string|null              $parent   The key holding `$payload`.
     *
     * @return array<array-key, mixed>
     */
    public static function redact( array $payload, ?string $parent = null ): array
    {
        foreach ( $payload as $key => $value ) {
            $normalized = is_string( $key ) ? strtolower( $key ) : null;

            if ( null !== $normalized && self::isSensitive( $normalized, $parent ) ) {
                $payload[ $key ] = self::MASK;

                continue;
            }

            if ( 'href' === $normalized && is_string( $value ) ) {
                $payload[ $key ] = self::redactPath( $value );

                continue;
            }

            if ( is_array( $value ) ) {
                // List entries inherit their list's parent key.
                $payload[ $key ] = self::redact( $value, $normalized ?? $parent );
            }
        }

        return $payload;
    }

    /**
     * Masks a Vault response: the token ids (the top-level `id`, and each
     * `payment_tokens[].id` of a list) as well as everything
     * {@see self::redact()} masks.
     *
     * @since 1.0.0
     *
     * @param  array<array-key, mixed>  $payload  Decoded Vault response.
     *
     * @return array<array-key, mixed>
     */
    public static function redactVault( array $payload ): array
    {
        if ( isset( $payload['id'] ) ) {
            $payload['id'] = self::MASK;
        }

        if ( isset( $payload['payment_tokens'] ) && is_array( $payload['payment_tokens'] ) ) {
            foreach ( $payload['payment_tokens'] as $index => $token ) {
                if ( is_array( $token ) && isset( $token['id'] ) ) {
                    $payload['payment_tokens'][ $index ]['id'] = self::MASK;
                }
            }
        }

        return self::redact( $payload );
    }

    /**
     * Masks the saved-payment token ids in an API path
     * (`/v3/vault/payment-tokens/{id}` → `/v3/vault/payment-tokens/[redacted]`).
     *
     * @since 1.0.0
     *
     * @param  string  $path  API path or URL.
     *
     * @return string
     */
    public static function redactPath( string $path ): string
    {
        $pattern = '#/(' . implode( '|', array_map( static fn ( string $segment ): string => preg_quote( $segment, '#' ), self::TOKEN_PATHS ) ) . ')/[^/?\#]+#i';

        return (string) preg_replace( $pattern, '/$1/' . self::MASK, $path );
    }

    /**
     * Whether a path is a Vault token resource.
     *
     * @since 1.0.0
     *
     * @param  string  $path  API path.
     *
     * @return bool
     */
    public static function isVaultPath( string $path ): bool
    {
        return str_starts_with( '/' . ltrim( $path, '/' ), '/v3/vault/' );
    }

    /**
     * Masks sensitive request or response headers.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $headers  Headers by name.
     *
     * @return array<string, mixed>
     */
    public static function redactHeaders( array $headers ): array
    {
        foreach ( array_keys( $headers ) as $name ) {
            if ( in_array( strtolower( (string) $name ), self::HEADERS, true ) ) {
                $headers[ $name ] = self::MASK;
            }
        }

        return $headers;
    }

    /**
     * Whether a key's value must be masked.
     *
     * @since 1.0.0
     *
     * @param  string       $key     Lower-cased key.
     * @param  string|null  $parent  Lower-cased parent key.
     *
     * @return bool
     */
    private static function isSensitive( string $key, ?string $parent ): bool
    {
        if ( in_array( $key, self::KEYS, true ) ) {
            return true;
        }

        if ( 'value' === $key && 'details' === $parent ) {
            // Error details echo the rejected input, which may be card data.
            return true;
        }

        return 'name' === $key && null !== $parent && in_array( $parent, self::NAME_PARENTS, true );
    }
}
