<?php

/**
 * PayPal settings group.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Settings;

use ArtisanPackUI\Ecommerce\Registries\SettingsRegistry;
use ArtisanPackUI\Ecommerce\Settings\SettingDefinition;
use ArtisanPackUI\Ecommerce\Settings\SettingSecret;
use ArtisanPackUI\Ecommerce\Settings\SettingsRepository;
use ArtisanPackUI\EcommercePayPal\Api\PayPalCredentials;
use ArtisanPackUI\EcommercePayPal\Api\PayPalTokenProvider;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Support\PayPalEnvironment;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exposes the package config in the engine's settings screen (spec §5,
 * engine docs/settings.md).
 *
 * Adds the `paypal` group, one setting per editable key (each overlaying
 * its `artisanpack.ecommerce-paypal.*` config key), the client secret and
 * webhook ids as secrets (shown as configured or not, never returned), and
 * a `paypal` constraint that refuses settings that can't work together.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalSettings
{
    /**
     * The settings group key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const GROUP = 'paypal';

    /**
     * The config prefix every setting overlays.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CONFIG_PREFIX = 'artisanpack.ecommerce-paypal.';

    /**
     * Cache key prefix for live credential checks.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CREDENTIAL_CHECK_CACHE_PREFIX = 'artisanpack.ecommerce-paypal.credential-check.';

    /**
     * How long a credential check result is cached, in seconds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const CREDENTIAL_CHECK_TTL = 600;

    /**
     * Registers the group, settings, secrets, and constraint.
     *
     * @since 1.0.0
     *
     * @param  SettingsRegistry    $registry    Settings registry.
     * @param  SettingsRepository  $repository  Settings repository.
     *
     * @return void
     */
    public static function register( SettingsRegistry $registry, SettingsRepository $repository ): void
    {
        $registry->addGroup(
            self::GROUP,
            __( 'PayPal' ),
            55,
            __( 'The PayPal client secret and webhook ids are read from the environment and are never shown or stored here.' ),
        );

        foreach ( self::definitions() as $definition ) {
            $registry->define( $definition );
        }

        foreach ( self::secrets() as $secret ) {
            $registry->addSecret( $secret );
        }

        $repository->constrain( self::GROUP, self::constraint() );
    }

    /**
     * The editable settings.
     *
     * @since 1.0.0
     *
     * @return array<int, SettingDefinition>
     */
    public static function definitions(): array
    {
        return [
            self::define( 'enabled', 'boolean', __( 'Enable PayPal' ), [ 'boolean' ], __( 'Offer PayPal at checkout. The client id and secret must be set.' ), 10 ),
            self::define( 'mode', 'select', __( 'Mode' ), [ 'required', 'in:sandbox,live' ], __( 'Use sandbox to test with PayPal sandbox accounts. Live takes real payments.' ), 20, static fn (): array => [
                PayPalEnvironment::SANDBOX => __( 'Sandbox' ),
                PayPalEnvironment::LIVE    => __( 'Live' ),
            ] ),
            self::define( 'client_id', 'string', __( 'Client ID' ), [ 'nullable', 'string', 'max:255' ], __( 'From your REST app in the PayPal developer dashboard.' ), 30 ),
            self::define( 'intent', 'select', __( 'Payment action' ), [ 'required', 'in:capture,authorize' ], __( 'Capture takes the payment when the order is placed. Authorize only holds it, to capture later.' ), 40, static fn (): array => [
                'capture'   => __( 'Capture' ),
                'authorize' => __( 'Authorize' ),
            ] ),
            self::define( 'brand_name', 'string', __( 'Brand name' ), [ 'nullable', 'string', 'max:127' ], __( 'Shown on PayPal\'s pages. Leave empty to use the store name.' ), 50 ),
            self::define( 'soft_descriptor', 'string', __( 'Statement descriptor' ), [ 'nullable', 'string', 'max:22' ], __( 'Shown on the buyer\'s card statement (22 characters at most).' ), 60 ),
            self::define( 'methods.paypal.enabled', 'boolean', __( 'PayPal' ), [ 'boolean' ], __( 'Offer the PayPal buttons.' ), 100 ),
            self::define( 'methods.paypal.pay_later', 'boolean', __( 'Pay Later' ), [ 'boolean' ], __( 'Offer Pay Later in the PayPal buttons where the buyer is eligible.' ), 110 ),
            self::define( 'methods.paypal.venmo', 'boolean', __( 'Venmo' ), [ 'boolean' ], __( 'Offer Venmo in the PayPal buttons where the buyer is eligible (US only).' ), 120 ),
            self::define( 'methods.card.enabled', 'boolean', __( 'Credit and debit cards' ), [ 'boolean' ], __( 'Offer card fields hosted by PayPal.' ), 130 ),
            self::define( 'methods.apple_pay.enabled', 'boolean', __( 'Apple Pay' ), [ 'boolean' ], __( 'Offer Apple Pay. Register your domain in the PayPal dashboard first.' ), 140 ),
            self::define( 'methods.apple_pay.domain_association', 'text', __( 'Apple Pay domain association' ), [ 'nullable', 'string', 'max:65535' ], __( 'The Apple domain association file from the PayPal dashboard: its contents, a path to it, or an HTTPS URL.' ), 150 ),
            self::define( 'methods.google_pay.enabled', 'boolean', __( 'Google Pay' ), [ 'boolean' ], __( 'Offer Google Pay.' ), 160 ),
            self::define( 'pay_later_messaging.checkout', 'boolean', __( 'Pay Later messages at checkout' ), [ 'boolean' ], null, 200 ),
            self::define( 'pay_later_messaging.cart', 'boolean', __( 'Pay Later messages in the cart' ), [ 'boolean' ], null, 210 ),
            self::define( 'pay_later_messaging.product', 'boolean', __( 'Pay Later messages on product pages' ), [ 'boolean' ], null, 220 ),
            self::define( 'vault.enabled', 'boolean', __( 'Saved payment methods' ), [ 'boolean' ], __( 'Let signed-in shoppers save PayPal and cards for faster checkout.' ), 300 ),
        ];
    }

    /**
     * The credentials listed on the group.
     *
     * @since 1.0.0
     *
     * @return array<int, SettingSecret>
     */
    public static function secrets(): array
    {
        return [
            new SettingSecret( self::CONFIG_PREFIX . 'client_secret', self::GROUP, __( 'Client secret' ), 'PAYPAL_CLIENT_SECRET' ),
            new SettingSecret( self::CONFIG_PREFIX . 'webhook_id', self::GROUP, __( 'Webhook ID' ), 'PAYPAL_WEBHOOK_ID' ),
        ];
    }

    /**
     * The setting key for a config key (`methods.card.enabled` →
     * `paypal.methods.card.enabled`).
     *
     * @since 1.0.0
     *
     * @param  string  $configKey  Key relative to `artisanpack.ecommerce-paypal`.
     *
     * @return string
     */
    public static function key( string $configKey ): string
    {
        return self::GROUP . '.' . $configKey;
    }

    /**
     * The `paypal` constraint, run on the combined settings before every
     * write:
     *
     * - `paypal-live-credentials-invalid` — PayPal is enabled in live mode,
     *   but PayPal refuses a live token for the credentials (checked against
     *   PayPal; a result is cached for 10 minutes, and a check that can't
     *   reach PayPal doesn't block the write). Disabling PayPal is never
     *   blocked by this check;
     * - `paypal-not-configured` — PayPal is enabled without a client id and
     *   secret;
     * - `paypal-vault-unavailable` — saved payment methods are on, but
     *   neither PayPal nor cards are;
     * - `paypal-apple-pay-domain-missing` — Apple Pay is on without a domain
     *   association file.
     *
     * @since 1.0.0
     *
     * @return Closure(array<string, mixed>): array<int, array{field: string|null, code: string, message: string}>
     */
    public static function constraint(): Closure
    {
        return static function ( array $values ): array {
            $value  = static fn ( string $configKey ): mixed => array_key_exists( self::key( $configKey ), $values )
                ? $values[ self::key( $configKey ) ]
                : config( self::CONFIG_PREFIX . $configKey );
            $errors = [];

            $enabled     = (bool) $value( 'enabled' );
            $mode        = strtolower( trim( (string) $value( 'mode' ) ) );
            $credentials = new PayPalCredentials(
                trim( (string) $value( 'client_id' ) ),
                trim( (string) config( self::CONFIG_PREFIX . 'client_secret', '' ) ),
                PayPalEnvironment::LIVE === $mode ? PayPalEnvironment::live() : PayPalEnvironment::sandbox(),
            );

            if ( $enabled && ! $credentials->isComplete() ) {
                $errors[] = [
                    'field'   => self::key( 'enabled' ),
                    'code'    => 'paypal-not-configured',
                    'message' => __( 'PayPal can\'t be enabled until the client ID and the client secret (PAYPAL_CLIENT_SECRET) are set.' ),
                ];
            }

            if ( $enabled && PayPalEnvironment::LIVE === $mode && $credentials->isComplete() && false === self::liveCredentialsWork( $credentials ) ) {
                $errors[] = [
                    'field'   => self::key( 'mode' ),
                    'code'    => 'paypal-live-credentials-invalid',
                    'message' => __( 'PayPal refused these credentials in live mode. Use the live client ID and secret from your PayPal dashboard; sandbox credentials only work in sandbox mode.' ),
                ];
            }

            if ( (bool) $value( 'vault.enabled' ) && ! (bool) $value( 'methods.paypal.enabled' ) && ! (bool) $value( 'methods.card.enabled' ) ) {
                $errors[] = [
                    'field'   => self::key( 'vault.enabled' ),
                    'code'    => 'paypal-vault-unavailable',
                    'message' => __( 'Saved payment methods need PayPal or credit and debit cards enabled.' ),
                ];
            }

            if ( (bool) $value( 'methods.apple_pay.enabled' ) && '' === trim( (string) $value( 'methods.apple_pay.domain_association' ) ) ) {
                $errors[] = [
                    'field'   => self::key( 'methods.apple_pay.enabled' ),
                    'code'    => 'paypal-apple-pay-domain-missing',
                    'message' => __( 'Apple Pay needs the domain association file from the PayPal dashboard. Add it before enabling Apple Pay.' ),
                ];
            }

            return $errors;
        };
    }

    /**
     * Whether PayPal issues a live token for `$credentials`.
     *
     * Definitive answers are cached for 10 minutes per credential
     * fingerprint (never the secret itself). When PayPal can't be reached
     * the answer is null and nothing is cached, so an outage doesn't block
     * saving settings.
     *
     * @since 1.0.0
     *
     * @param  PayPalCredentials  $credentials  Live credentials.
     *
     * @return bool|null
     */
    public static function liveCredentialsWork( PayPalCredentials $credentials ): ?bool
    {
        $key    = self::CREDENTIAL_CHECK_CACHE_PREFIX . $credentials->fingerprint();
        $cached = Cache::get( $key );

        if ( is_bool( $cached ) ) {
            return $cached;
        }

        try {
            $works = app( PayPalTokenProvider::class )->verify( $credentials );
        } catch ( PayPalApiException $exception ) {
            Log::warning( 'The PayPal live credential check could not reach PayPal.', $exception->context() );

            return null;
        } catch ( Throwable $exception ) {
            Log::warning( 'The PayPal live credential check failed.', [ 'exception' => $exception::class ] );

            return null;
        }

        Cache::put( $key, $works, self::CREDENTIAL_CHECK_TTL );

        return $works;
    }

    /**
     * Builds one setting.
     *
     * @since 1.0.0
     *
     * @param  string                                 $configKey    Key relative to `artisanpack.ecommerce-paypal`.
     * @param  string                                 $type         Setting type.
     * @param  string                                 $label        Translated label.
     * @param  array<int, mixed>                      $rules        Validation rules.
     * @param  string|null                            $description  Translated help text.
     * @param  int                                    $position     Sort order.
     * @param  Closure(): array<string, string>|null  $options      Choices for a select.
     *
     * @return SettingDefinition
     */
    private static function define(
        string $configKey,
        string $type,
        string $label,
        array $rules,
        ?string $description,
        int $position,
        ?Closure $options = null,
    ): SettingDefinition {
        return new SettingDefinition(
            key: self::key( $configKey ),
            group: self::GROUP,
            type: $type,
            label: $label,
            rules: $rules,
            description: $description,
            options: $options,
            configKey: self::CONFIG_PREFIX . $configKey,
            position: $position,
        );
    }
}
