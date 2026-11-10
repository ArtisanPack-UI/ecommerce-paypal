<?php

declare( strict_types=1 );

namespace Tests\Browser\Support;

use Tests\Sandbox\Support\PayPalSandbox;

/**
 * Drives PayPal's own sandbox pages in the browser journeys (spec §12.4).
 *
 * Every selector for a page PayPal hosts lives here, so a PayPal redesign
 * is fixed in one place. Pest's browser plugin can't follow a popup
 * window, so PayPal approval uses PayPal's full-page flow
 * (`checkoutnow?token=`), the same pages the SDK falls back to when a
 * popup is blocked.
 *
 * Environment:
 *
 * - `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_CLIENT_SECRET` — the app;
 * - `PAYPAL_SANDBOX_BUYER_EMAIL`, `PAYPAL_SANDBOX_BUYER_PASSWORD` — a
 *   sandbox personal account, for wallet approval;
 * - `PAYPAL_SANDBOX_CARD_NUMBER` — a card that pays without 3-D Secure
 *   (default {@see PayPalSandbox::DEFAULT_CARD});
 * - `PAYPAL_SANDBOX_3DS_FRICTIONLESS_CARD`, `PAYPAL_SANDBOX_3DS_CHALLENGE_CARD`,
 *   `PAYPAL_SANDBOX_3DS_FAILED_CARD` — cards from PayPal's 3-D Secure
 *   test card table (developer.paypal.com, "3D Secure" testing), and
 *   `PAYPAL_SANDBOX_3DS_CODE` — the challenge code those cards take.
 *
 * A test that needs a value that isn't set is skipped.
 *
 * @since 1.0.0
 */
final class PayPalSandboxPages
{
    /**
     * PayPal's full-page approval for an Order.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const APPROVAL_URL = 'https://www.sandbox.paypal.com/checkoutnow?token=%s';

    /**
     * Login: email, "Next", password, "Log In".
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const LOGIN = [
        'email'    => '#email',
        'next'     => '#btnNext',
        'password' => '#password',
        'submit'   => '#btnLogin',
    ];

    /**
     * The review page's pay button.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PAY_BUTTON = '#payment-submit-btn';

    /**
     * The review page's "Cancel and return to {store}" link.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CANCEL_LINK = 'a[data-testid="cancel-link"], #cancelLink';

    /**
     * The input inside each hosted card field iframe.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CARD_INPUT = 'input';

    /**
     * The 3-D Secure challenge: PayPal's modal frame, the bank's frame in
     * it, the code input, and its submit button.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    public const THREE_D_SECURE = [
        'modal'  => 'iframe[title*="3D"], iframe[name^="__zoid__three_domain_secure"]',
        'bank'   => 'iframe',
        'code'   => 'input[name="challengeDataEntry"], input[type="text"]',
        'submit' => 'input[type="submit"], button[type="submit"]',
    ];

    /**
     * An environment value, or null when it isn't set.
     *
     * @since 1.0.0
     *
     * @param  string  $key  Variable name.
     *
     * @return string|null
     */
    public static function env( string $key ): ?string
    {
        $value = PayPalSandbox::env( $key );

        return '' === $value ? null : $value;
    }

    /**
     * Skips the running test unless every `$keys` variable is set.
     *
     * @since 1.0.0
     *
     * @param  array<int, string>  $keys  Variable names.
     *
     * @return void
     */
    public static function require( array $keys ): void
    {
        $missing = array_values( array_filter( $keys, static fn ( string $key ): bool => null === self::env( $key ) ) );

        if ( [] !== $missing ) {
            test()->markTestSkipped( 'Set ' . implode( ', ', $missing ) . ' to run this sandbox journey.' );
        }
    }

    /**
     * Approves `$orderId` as the sandbox buyer on PayPal's full-page flow;
     * PayPal then sends the browser to the Order's return URL.
     *
     * @since 1.0.0
     *
     * @param  mixed   $page     The page.
     * @param  string  $orderId  PayPal Order id.
     *
     * @return mixed
     */
    public static function approve( mixed $page, string $orderId ): mixed
    {
        $page->navigate( sprintf( self::APPROVAL_URL, $orderId ) )
            ->type( self::LOGIN['email'], (string) self::env( 'PAYPAL_SANDBOX_BUYER_EMAIL' ) );

        // PayPal asks for the email and password on one page or two.
        $page->script( "() => document.querySelector( '" . self::LOGIN['next'] . "' )?.click()" );

        return $page->type( self::LOGIN['password'], (string) self::env( 'PAYPAL_SANDBOX_BUYER_PASSWORD' ) )
            ->click( self::LOGIN['submit'] )
            ->click( self::PAY_BUTTON );
    }

    /**
     * Opens PayPal's page for `$orderId` and cancels back to the store.
     *
     * @since 1.0.0
     *
     * @param  mixed   $page     The page.
     * @param  string  $orderId  PayPal Order id.
     *
     * @return mixed
     */
    public static function cancel( mixed $page, string $orderId ): mixed
    {
        return $page->navigate( sprintf( self::APPROVAL_URL, $orderId ) )
            ->click( self::CANCEL_LINK );
    }

    /**
     * Types a card into the hosted card fields.
     *
     * @since 1.0.0
     *
     * @param  mixed   $page    The page.
     * @param  string  $number  Card number.
     *
     * @return mixed
     */
    public static function fillCard( mixed $page, string $number ): mixed
    {
        $values = [
            'name'   => 'Ada Lovelace',
            'number' => $number,
            'expiry' => now()->addYears( 3 )->format( 'm/y' ),
            'cvv'    => '123',
        ];

        foreach ( $values as $field => $value ) {
            $page->withinFrame( '[data-paypal-card-field="' . $field . '"] iframe', static function ( mixed $frame ) use ( $value ): void {
                $frame->type( self::CARD_INPUT, $value );
            } );
        }

        return $page;
    }

    /**
     * Completes the bank's 3-D Secure challenge with the sandbox code.
     *
     * @since 1.0.0
     *
     * @param  mixed  $page  The page.
     *
     * @return mixed
     */
    public static function completeChallenge( mixed $page ): mixed
    {
        return $page->withinFrame( self::THREE_D_SECURE['modal'], static function ( mixed $modal ): void {
            $modal->withinFrame( self::THREE_D_SECURE['bank'], static function ( mixed $bank ): void {
                $bank->type( self::THREE_D_SECURE['code'], (string) self::env( 'PAYPAL_SANDBOX_3DS_CODE' ) )
                    ->click( self::THREE_D_SECURE['submit'] );
            } );
        } );
    }
}
