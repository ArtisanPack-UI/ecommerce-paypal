<?php

/**
 * PayPal REST app credentials.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Api;

use ArtisanPackUI\EcommercePayPal\Support\PayPalEnvironment;
use SensitiveParameter;

/**
 * A client id and secret for one PayPal environment.
 *
 * The secret is hidden from `var_dump()` / `print_r()` and never serialized.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalCredentials
{
    /**
     * Creates the credentials.
     *
     * @since 1.0.0
     *
     * @param  string             $clientId      REST app client id.
     * @param  string             $clientSecret  REST app secret.
     * @param  PayPalEnvironment  $environment   The environment they belong to.
     */
    public function __construct(
        public readonly string $clientId,
        #[SensitiveParameter]
        private readonly string $clientSecret,
        public readonly PayPalEnvironment $environment,
    ) {
    }

    /**
     * Hides the secret from debug output.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'clientId'     => $this->clientId,
            'clientSecret' => '[redacted]',
            'environment'  => $this->environment->mode,
        ];
    }

    /**
     * The configured credentials.
     *
     * @since 1.0.0
     *
     * @param  PayPalEnvironment|null  $environment  Override the configured mode.
     *
     * @return self
     */
    public static function fromConfig( ?PayPalEnvironment $environment = null ): self
    {
        return new self(
            trim( (string) config( 'artisanpack.ecommerce-paypal.client_id', '' ) ),
            trim( (string) config( 'artisanpack.ecommerce-paypal.client_secret', '' ) ),
            $environment ?? PayPalEnvironment::fromConfig(),
        );
    }

    /**
     * Whether the configured client id and secret are both set.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public static function configured(): bool
    {
        return self::fromConfig()->isComplete();
    }

    /**
     * Whether both the client id and the secret are set.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret;
    }

    /**
     * The secret, for the token request only.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function secret(): string
    {
        return $this->clientSecret;
    }

    /**
     * A stable, secret-free key for caching per mode and client id.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function cacheKey(): string
    {
        return $this->environment->mode . '.' . hash( 'sha256', $this->clientId );
    }

    /**
     * A key that changes whenever any credential changes, for caching
     * credential checks without keeping the secret.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function fingerprint(): string
    {
        return $this->environment->mode . '.' . hash( 'sha256', $this->clientId . "\0" . $this->clientSecret );
    }
}
