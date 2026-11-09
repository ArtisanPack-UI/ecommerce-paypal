<?php

/**
 * Keeps uncaptured PayPal authorizations alive.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Payments;

use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\Ecommerce\Services\OrderNoteService;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The authorization lifecycle for `authorize`-intent stores (spec §7.4).
 *
 * PayPal guarantees an authorization's funds for a 3-day honor period and
 * keeps the authorization valid for 29 days. Run daily (by
 * `ecommerce-paypal:authorizations:reauthorize`), this:
 *
 * - reauthorizes each uncaptured authorization once its honor period is
 *   over, while it is still valid (PayPal allows one reauthorization), and
 *   writes an order note;
 * - fires `ap.ecommerce.paypal.authorizationExpiring` `(Order $order,
 *   CarbonImmutable $expiresAt)` once, 2 days before the authorization
 *   expires, so the store can capture or contact the shopper.
 *
 * Progress is kept on `orders.meta.paypal.authorization`
 * (`reauthorized_at`, `expiry_notified_at`), so a second run on the same
 * day changes nothing.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class AuthorizationLifecycle
{
    /**
     * Days PayPal guarantees an authorization's funds.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const HONOR_PERIOD_DAYS = 3;

    /**
     * Days before expiry that `authorizationExpiring` fires.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const EXPIRY_WARNING_DAYS = 2;

    /**
     * The hook fired before an authorization expires.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const EXPIRING_HOOK = 'ap.ecommerce.paypal.authorizationExpiring';

    /**
     * Creates the service.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient      $client  API client.
     * @param  OrderNoteService  $notes   Order notes.
     */
    public function __construct(
        protected PayPalClient $client,
        protected OrderNoteService $notes,
    ) {
    }

    /**
     * Runs the lifecycle over every PayPal order with an open
     * authorization.
     *
     * @since 1.0.0
     *
     * @param  CarbonImmutable|null  $now  The time to run at (now by default).
     *
     * @return array{checked: int, reauthorized: int, expiring: int, failed: int}
     */
    public function run( ?CarbonImmutable $now = null ): array
    {
        $now    = $now ?? CarbonImmutable::instance( Date::now() );
        $totals = [ 'checked' => 0, 'reauthorized' => 0, 'expiring' => 0, 'failed' => 0 ];
        $keys   = array_map( static fn ( string $gateway ): string => $gateway::KEY, EcommercePayPalServiceProvider::GATEWAYS );

        Order::query()
            ->whereIn( 'payment_gateway_key', $keys )
            ->whereNotNull( 'meta->' . PayPalOrderMeta::KEY . '->authorization->id' )
            ->chunkById( 100, function ( $orders ) use ( $now, &$totals ): void {
                foreach ( $orders as $order ) {
                    foreach ( $this->process( $order, $now ) as $outcome ) {
                        $totals[ $outcome ]++;
                    }
                }
            } );

        return $totals;
    }

    /**
     * Runs the lifecycle for one order.
     *
     * @since 1.0.0
     *
     * @param  Order            $order  Order.
     * @param  CarbonImmutable  $now    Current time.
     *
     * @return array<int, string> Outcomes (`checked`, `reauthorized`, `expiring`, `failed`).
     */
    public function process( Order $order, CarbonImmutable $now ): array
    {
        $authorization = (array) PayPalOrderMeta::get( $order, 'authorization', [] );

        if ( ! in_array( $authorization['status'] ?? null, [ 'CREATED', 'PENDING' ], true ) || null !== PayPalOrderMeta::get( $order, 'capture_id' ) ) {
            return [];
        }

        $authorizedAt = self::time( $authorization['authorized_at'] ?? null ) ?? self::time( $authorization['created_at'] ?? null );

        if ( null === $authorizedAt ) {
            return [];
        }

        $expiresAt = self::time( $authorization['expires_at'] ?? null ) ?? $authorizedAt->addDays( PayPalGateway::AUTHORIZATION_VALIDITY_DAYS );

        if ( $now->greaterThanOrEqualTo( $expiresAt ) ) {
            return [];
        }

        $outcomes  = [ 'checked' ];
        $createdAt = self::time( $authorization['created_at'] ?? null ) ?? $authorizedAt;

        // PayPal only reauthorizes a `CREATED` authorization; one pending
        // review still gets the expiry warning.
        if ( 'CREATED' === $authorization['status'] && empty( $authorization['reauthorized_at'] ) && $now->greaterThanOrEqualTo( $createdAt->addDays( self::HONOR_PERIOD_DAYS ) ) ) {
            $outcomes[] = $this->reauthorize( $order, (string) $authorization['id'], $expiresAt, $now ) ? 'reauthorized' : 'failed';
        }

        if ( empty( $authorization['expiry_notified_at'] ) && $now->greaterThanOrEqualTo( $expiresAt->subDays( self::EXPIRY_WARNING_DAYS ) ) ) {
            PayPalOrderMeta::merge( $order, [ 'authorization' => [ 'expiry_notified_at' => $now->toIso8601String() ] ] );

            doAction( self::EXPIRING_HOOK, $order, $expiresAt );

            $outcomes[] = 'expiring';
        }

        return $outcomes;
    }

    /**
     * Reauthorizes `$authorizationId` and records the new authorization.
     *
     * @since 1.0.0
     *
     * @param  Order            $order            Order.
     * @param  string           $authorizationId  Current authorization id.
     * @param  CarbonImmutable  $expiresAt        When the authorization stops being valid.
     * @param  CarbonImmutable  $now              Current time.
     *
     * @return bool Whether it was reauthorized.
     */
    protected function reauthorize( Order $order, string $authorizationId, CarbonImmutable $expiresAt, CarbonImmutable $now ): bool
    {
        try {
            $renewed = $this->client->payments()->reauthorize( $authorizationId, 'reauthorize-' . $authorizationId );
        } catch ( PayPalApiException $exception ) {
            Log::channel( self::logChannel() )->warning( 'Could not reauthorize a PayPal authorization.', [
                'order_id'         => $order->getKey(),
                'authorization_id' => $authorizationId,
                ...$exception->context(),
            ] );

            return false;
        }

        if ( '' === $renewed->id() ) {
            return false;
        }

        PayPalOrderMeta::merge( $order, [
            'authorization' => array_filter( [
                'id'              => $renewed->id(),
                'status'          => $renewed->status() ?? 'CREATED',
                'created_at'      => ( $renewed->createdAt() ?? $now )->toIso8601String(),
                'previous_id'     => $authorizationId,
                'reauthorized_at' => $now->toIso8601String(),
            ] ),
        ] );

        try {
            $this->notes->add( $order, __( 'PayPal authorization :old was reauthorized as :new after its 3-day honor period. It can be captured until :date.', [
                'old'  => $authorizationId,
                'new'  => $renewed->id(),
                'date' => $expiresAt->toDayDateTimeString(),
            ] ) );
        } catch ( Throwable $exception ) {
            Log::channel( self::logChannel() )->warning( 'Could not add the PayPal reauthorization note.', [
                'order_id' => $order->getKey(),
                'error'    => $exception->getMessage(),
            ] );
        }

        return true;
    }

    /**
     * Parses a stored ISO 8601 time.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Stored value.
     *
     * @return CarbonImmutable|null
     */
    private static function time( mixed $value ): ?CarbonImmutable
    {
        if ( ! is_string( $value ) || '' === $value ) {
            return null;
        }

        try {
            return CarbonImmutable::parse( $value );
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * The configured log channel, or null for the default.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    private static function logChannel(): ?string
    {
        $channel = config( 'artisanpack.ecommerce-paypal.logging.channel' );

        return is_string( $channel ) && '' !== $channel ? $channel : null;
    }
}
