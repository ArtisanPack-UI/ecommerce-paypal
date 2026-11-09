<?php

declare( strict_types=1 );

namespace Tests\Support;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use Money\Currency;
use Money\Money;

/**
 * Engine orders, sessions, and PayPal Order bodies for the payment tests.
 *
 * @since 1.0.0
 */
final class PayPalTestOrders
{
    /**
     * The PayPal Order id the fixtures use.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ORDER_ID = '5O190127TN364715T';

    /**
     * The capture id the fixtures use.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const CAPTURE_ID = '3C679366HH908993F';

    /**
     * The authorization id the fixtures use.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const AUTHORIZATION_ID = '0VF52814937998046';

    /**
     * Stores an engine order paid through PayPal ($100.00 USD by default).
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $attributes  Order attributes.
     * @param  array<string, mixed>  $paypal      `orders.meta.paypal`.
     *
     * @return Order
     */
    public static function create( array $attributes = [], array $paypal = [] ): Order
    {
        $currency = (string) ( $attributes['currency'] ?? 'USD' );

        return Order::factory()->create( array_merge( [
            'payment_gateway_key' => 'paypal',
            'payment_reference'   => self::ORDER_ID,
            'currency'            => $currency,
            'total_amount'        => 10000,
            'total_currency'      => $currency,
            'subtotal_amount'     => 10000,
            'meta'                => [] === $paypal ? [] : [ 'paypal' => $paypal ],
        ], $attributes ) );
    }

    /**
     * A session for the fixture Order.
     *
     * @since 1.0.0
     *
     * @param  string  $gateway   Gateway key.
     * @param  int     $amount    Minor amount.
     * @param  string  $currency  Currency.
     *
     * @return PaymentSession
     */
    public static function session( string $gateway = 'paypal', int $amount = 10000, string $currency = 'USD' ): PaymentSession
    {
        return new PaymentSession( $gateway, self::ORDER_ID, new Money( $amount, new Currency( $currency ) ), status: PaymentSession::STATUS_AUTHORIZED );
    }

    /**
     * A fixture Order body with changes applied by dot path.
     *
     * @since 1.0.0
     *
     * @param  string                $fixture  Fixture name.
     * @param  array<string, mixed>  $changes  Dot path => value (null removes).
     *
     * @return array<string, mixed>
     */
    public static function body( string $fixture, array $changes = [] ): array
    {
        $body = PayPalFake::fixture( $fixture );

        foreach ( $changes as $path => $value ) {
            if ( null === $value ) {
                data_forget( $body, $path );

                continue;
            }

            data_set( $body, $path, $value );
        }

        return $body;
    }

    /**
     * An approved Order paid with a card whose 3-D Secure result is `$shift`.
     *
     * @since 1.0.0
     *
     * @param  string|null  $shift   Liability shift, or null for none.
     * @param  string       $intent  `CAPTURE` or `AUTHORIZE`.
     *
     * @return array<string, mixed>
     */
    public static function approvedCard( ?string $shift, string $intent = 'CAPTURE' ): array
    {
        $card = [ 'brand' => 'VISA', 'last_digits' => '1111' ];

        if ( null !== $shift ) {
            $card['authentication_result'] = [ 'liability_shift' => $shift ];
        }

        return self::body( 'orders/approved', [ 'intent' => $intent, 'payment_source' => [ 'card' => $card ], 'payer' => null ] );
    }

    /**
     * An error body with one issue.
     *
     * @since 1.0.0
     *
     * @param  string  $issue  PayPal issue.
     *
     * @return array<string, mixed>
     */
    public static function issue( string $issue ): array
    {
        return [
            'name'     => 'UNPROCESSABLE_ENTITY',
            'message'  => 'The requested action could not be performed.',
            'details'  => [ [ 'issue' => $issue, 'description' => 'Test.' ] ],
            'debug_id' => 'test-debug-id',
        ];
    }
}
