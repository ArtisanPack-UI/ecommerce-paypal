<?php

/**
 * PayPal OAuth2 access token.
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

use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use SensitiveParameter;

/**
 * The `/v1/oauth2/token` response.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalAccessToken
{
    /**
     * Creates the token.
     *
     * @since 1.0.0
     *
     * @param  string       $accessToken  Bearer token.
     * @param  int          $expiresIn    Lifetime in seconds.
     * @param  string|null  $appId        PayPal app id.
     * @param  string|null  $scope        Space-separated granted scopes.
     */
    public function __construct(
        #[SensitiveParameter]
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly ?string $appId = null,
        public readonly ?string $scope = null,
    ) {
    }

    /**
     * Hides the token from debug output.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [ 'accessToken' => '[redacted]', 'expiresIn' => $this->expiresIn, 'appId' => $this->appId ];
    }

    /**
     * Builds the token from the decoded response.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $body  Decoded response.
     *
     * @throws PayPalApiException When the response has no token.
     *
     * @return self
     */
    public static function fromArray( array $body ): self
    {
        if ( ! isset( $body['access_token'] ) || ! is_string( $body['access_token'] ) || '' === $body['access_token'] ) {
            throw new PayPalApiException( 'PayPal did not return an access token.', 200, 'MISSING_ACCESS_TOKEN' );
        }

        return new self(
            $body['access_token'],
            max( 0, (int) ( $body['expires_in'] ?? 0 ) ),
            isset( $body['app_id'] ) ? (string) $body['app_id'] : null,
            isset( $body['scope'] ) ? (string) $body['scope'] : null,
        );
    }
}
