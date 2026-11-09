<?php

/**
 * PayPal environment (sandbox or live).
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

use InvalidArgumentException;

/**
 * Resolves the PayPal hosts for a mode.
 *
 * Every PayPal URL the package builds (the REST API, the web pages buyers
 * and merchants see, the webhook certificate hosts, and the dashboard
 * links in the admin panel) goes through here, so a store in `live` mode
 * can never call a sandbox host or the other way around.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalEnvironment
{
    /**
     * The sandbox mode.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const SANDBOX = 'sandbox';

    /**
     * The live mode.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const LIVE = 'live';

    /**
     * Every supported mode.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const MODES = [ self::SANDBOX, self::LIVE ];

    /**
     * Creates the environment.
     *
     * @since 1.0.0
     *
     * @param  string  $mode  `sandbox` or `live`.
     *
     * @throws InvalidArgumentException When the mode is unknown.
     */
    public function __construct( public readonly string $mode )
    {
        if ( ! in_array( $mode, self::MODES, true ) ) {
            throw new InvalidArgumentException( sprintf( 'Unknown PayPal mode "%s"; use "sandbox" or "live".', $mode ) );
        }
    }

    /**
     * The environment for the configured mode.
     *
     * An unknown configured mode falls back to sandbox, so a typo can never
     * send live traffic anywhere unexpected.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function fromConfig(): self
    {
        $mode = strtolower( trim( (string) config( 'artisanpack.ecommerce-paypal.mode', self::SANDBOX ) ) );

        return new self( in_array( $mode, self::MODES, true ) ? $mode : self::SANDBOX );
    }

    /**
     * The sandbox environment.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function sandbox(): self
    {
        return new self( self::SANDBOX );
    }

    /**
     * The live environment.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function live(): self
    {
        return new self( self::LIVE );
    }

    /**
     * Whether this is the live environment.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isLive(): bool
    {
        return self::LIVE === $this->mode;
    }

    /**
     * The REST API base URL.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function apiBase(): string
    {
        return $this->isLive() ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * The website base URL (checkout pages, the merchant's activity).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function webBase(): string
    {
        return $this->isLive() ? 'https://www.paypal.com' : 'https://www.sandbox.paypal.com';
    }

    /**
     * The host PayPal serves webhook signing certificates from.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function certificateHost(): string
    {
        return $this->isLive() ? 'api.paypal.com' : 'api.sandbox.paypal.com';
    }

    /**
     * The merchant's activity page for a transaction (capture, refund, or
     * authorization id).
     *
     * @since 1.0.0
     *
     * @param  string  $transactionId  PayPal transaction id.
     *
     * @return string
     */
    public function transactionUrl( string $transactionId ): string
    {
        return $this->webBase() . '/activity/payment/' . rawurlencode( $transactionId );
    }

    /**
     * The merchant's Resolution Center page for a dispute.
     *
     * @since 1.0.0
     *
     * @param  string  $disputeId  PayPal dispute id.
     *
     * @return string
     */
    public function disputeUrl( string $disputeId ): string
    {
        return $this->webBase() . '/resolutioncenter/view/' . rawurlencode( $disputeId );
    }

    /**
     * The developer dashboard's app list for this mode, where credentials
     * and webhooks are managed.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function developerDashboardUrl(): string
    {
        return 'https://developer.paypal.com/dashboard/applications/' . $this->mode;
    }
}
