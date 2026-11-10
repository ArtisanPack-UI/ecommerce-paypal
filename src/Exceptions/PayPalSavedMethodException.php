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

use ArtisanPackUI\Ecommerce\Exceptions\CheckoutException;
use ArtisanPackUI\Ecommerce\Services\CheckoutService;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use Throwable;

/**
 * A saved payment method can't be used to pay (spec §10.2): it isn't the
 * shopper's, it was removed, PayPal declined it (it is kept), or PayPal
 * reported the vaulted token invalid (in which case it has been
 * soft-deleted locally).
 *
 * The message is translated and safe to show the shopper. It is a
 * {@see CheckoutException} on the `payment_options` field, so the engine's
 * REST and GraphQL checkout answer it as a 422 with its code
 * (`saved-payment-method-unavailable` or `saved-payment-method-invalid`).
 *
 * When PayPal rejected the token, {@see self::$token} names it. The gateway
 * soft-deletes it straight away and again once the request ends, because
 * the engine's checkout runs the session inside a database transaction that
 * rolls the first delete back with this exception.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalSavedMethodException extends CheckoutException
{
    /**
     * The code for a saved method that isn't the shopper's to use.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const UNAVAILABLE = 'saved-payment-method-unavailable';

    /**
     * The code for a saved method PayPal no longer accepts.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const INVALID = 'saved-payment-method-invalid';

    /**
     * PayPal's error, when PayPal rejected the token.
     *
     * @since 1.0.0
     *
     * @var Throwable|null
     */
    public readonly ?Throwable $paypalError;

    /**
     * Creates the exception.
     *
     * @since 1.0.0
     *
     * @param  string                   $message    Shopper-facing message.
     * @param  PayPalPaymentToken|null  $token      The token PayPal rejected, if it did.
     * @param  Throwable|null           $previous   PayPal's error.
     * @param  string                   $errorCode  {@see self::UNAVAILABLE} or {@see self::INVALID}.
     */
    public function __construct( string $message, public readonly ?PayPalPaymentToken $token = null, ?Throwable $previous = null, string $errorCode = self::UNAVAILABLE )
    {
        parent::__construct( CheckoutService::PAYMENT_OPTIONS, $errorCode, $message );

        $this->paypalError = $previous;
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
        return new self( __( 'That saved card can no longer be used, so we removed it. Choose another card or enter a new one.' ), $token, $previous, self::INVALID );
    }

    /**
     * PayPal declined the saved card but still holds it, so it was kept.
     *
     * @since 1.0.0
     *
     * @param  PayPalApiException|null  $previous  PayPal's error.
     *
     * @return self
     */
    public static function declined( ?PayPalApiException $previous = null ): self
    {
        return new self( __( 'This card was declined. Choose another payment method.' ), null, $previous, self::UNAVAILABLE );
    }
}
