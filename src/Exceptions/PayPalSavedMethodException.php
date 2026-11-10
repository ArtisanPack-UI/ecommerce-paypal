<?php

/**
 * Saved payment method exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Exceptions;

use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use RuntimeException;
use Throwable;

/**
 * A saved payment method can't be used to pay (spec §10.2): it isn't the
 * shopper's, it was removed, or PayPal reported the vaulted token invalid
 * (in which case it has been soft-deleted locally).
 *
 * The message is translated and safe to show the shopper. When PayPal
 * rejected the token, {@see self::$token} names it: the gateway soft-deletes
 * it straight away, but a caller that ran the payment session inside a
 * database transaction (the engine's checkout does) sees that rolled back
 * with the exception, and should forget the token again afterwards.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalSavedMethodException extends RuntimeException
{
    /**
     * Creates the exception.
     *
     * @since 1.0.0
     *
     * @param  string                   $message   Shopper-facing message.
     * @param  PayPalPaymentToken|null  $token     The token PayPal rejected, if it did.
     * @param  Throwable|null           $previous  PayPal's error.
     */
    public function __construct( string $message, public readonly ?PayPalPaymentToken $token = null, ?Throwable $previous = null )
    {
        parent::__construct( $message, 0, $previous );
    }

    /**
     * The saved method isn't available to this shopper.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function unavailable(): self
    {
        return new self( __( 'That saved card isn\'t available. Choose another card or enter a new one.' ) );
    }

    /**
     * PayPal no longer accepts the saved method; it was removed.
     *
     * @since 1.0.0
     *
     * @param  PayPalPaymentToken       $token     The rejected token.
     * @param  PayPalApiException|null  $previous  PayPal's error.
     *
     * @return self
     */
    public static function invalid( PayPalPaymentToken $token, ?PayPalApiException $previous = null ): self
    {
        return new self( __( 'That saved card can no longer be used, so we removed it. Choose another card or enter a new one.' ), $token, $previous );
    }
}
