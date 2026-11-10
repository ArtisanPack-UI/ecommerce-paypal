<?php

/**
 * Admin order panel: PayPal transaction details.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Livewire\Admin;

use ArtisanPackUI\Ecommerce\Auth\EcommerceAuthorizer;
use ArtisanPackUI\Ecommerce\Models\Order;
use ArtisanPackUI\EcommercePayPal\EcommercePayPalServiceProvider;
use ArtisanPackUI\EcommercePayPal\Support\PayPalEnvironment;
use ArtisanPackUI\EcommercePayPal\Support\PayPalOrderMeta;
use ArtisanPackUI\EcommercePayPal\Webhooks\Handlers\DisputeEventHandler;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * `<livewire:artisanpack-ecommerce-paypal-order-panel :order="$order" />`
 *
 * A side panel on admin-livewire's order screen (spec §11), registered in
 * its `OrderPanelRegistry` only when that package is installed. It answers
 * "did this payment go through?" without the PayPal dashboard: the mode,
 * the PayPal Order, capture, and authorization ids (the capture and
 * authorization link to the transaction in PayPal for the mode the order
 * was paid in), the payer's
 * email, the funding source, the 3-D Secure liability shift, when the
 * authorization expires, and any dispute.
 *
 * Mounting and every request need the engine's `order.view` ability (a
 * shopper owning the order isn't enough); without it the panel is a `403`. For an order not paid
 * with PayPal it renders nothing. Everything shown comes from
 * `orders.meta.paypal`, so the panel never calls PayPal.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class PayPalOrderPanel extends Component
{
    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-order-panel';

    /**
     * The `OrderPanelRegistry` key.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const PANEL_KEY = 'paypal';

    /**
     * Where on the order screen the panel goes.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COLUMN = 'side';

    /**
     * The panel's position in its column.
     *
     * @since 1.0.0
     *
     * @var int
     */
    public const POSITION = 60;

    /**
     * The order shown.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $orderId = 0;

    /**
     * Loads and authorizes the order.
     *
     * @since 1.0.0
     *
     * @param  int|Order|string  $order  The order, or its id.
     *
     * @return void
     */
    public function mount( Order|int|string $order ): void
    {
        $this->orderId = $order instanceof Order ? (int) $order->getKey() : (int) $order;

        $this->authorizeView();
    }

    /**
     * Re-authorizes on every request.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function hydrate(): void
    {
        $this->authorizeView();
    }

    /**
     * Re-renders when another panel changes the order (a refund, a capture).
     *
     * @since 1.0.0
     *
     * @return void
     */
    #[On( 'ecommerce-admin-order-updated' )]
    public function refreshPanel(): void
    {
    }

    /**
     * Renders the panel.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        $order = Order::query()->find( $this->orderId );

        return view( 'ecommerce-paypal::livewire.admin.order-panel', [
            'details' => null === $order ? null : self::details( $order ),
        ] );
    }

    /**
     * What the panel shows for `$order`, or null when it wasn't paid with
     * PayPal.
     *
     * @since 1.0.0
     *
     * @param  Order  $order  Order.
     *
     * @return array{mode: string, live: bool, ids: array<int, array{label: string, value: string, url: string|null}>, payer_email: string|null, funding_source: string|null, funding_source_key: string|null, card: string|null, liability_shift: string|null, authorization: array{status: string|null, status_label: string, expires_at: Carbon|null, expired: bool}|null, disputes: array<int, array{id: string, status: string|null, status_label: string, outcome: string|null, outcome_label: string|null, reason: string|null, reason_label: string|null, amount: string|null, respond_by: Carbon|null, url: string|null}>}|null
     */
    public static function details( Order $order ): ?array
    {
        $meta = PayPalOrderMeta::all( $order );

        if ( ! self::isPayPal( $order, $meta ) ) {
            return null;
        }

        $mode        = 'live' === ( $meta['mode'] ?? null ) ? 'live' : 'sandbox';
        $environment = new PayPalEnvironment( $mode );
        $ids         = [];

        // Captures and authorizations are transactions in the merchant's
        // activity; an Order id isn't, so it has no dashboard page of its
        // own and is shown without a link.
        foreach ( [
            'order_id'         => [ __( 'PayPal Order' ), false ],
            'capture_id'       => [ __( 'Capture' ), true ],
            'authorization.id' => [ __( 'Authorization' ), true ],
        ] as $path => [ $label, $linked ] ) {
            $id = data_get( $meta, $path );

            if ( is_string( $id ) && '' !== trim( $id ) ) {
                $ids[] = [ 'label' => $label, 'value' => $id, 'url' => $linked ? $environment->transactionUrl( $id ) : null ];
            }
        }

        $card    = (array) ( $meta['card'] ?? [] );
        $funding = self::text( $meta['funding_source'] ?? null );

        return [
            'mode'               => $mode,
            'live'               => 'live' === $mode,
            'ids'                => $ids,
            'payer_email'        => self::text( data_get( $meta, 'payer.email' ) ),
            'funding_source'     => self::fundingSource( $funding ),
            'funding_source_key' => $funding,
            'card'               => isset( $card['last_digits'] ) ? __( ':brand ending in :digits', [ 'brand' => ucwords( strtolower( str_replace( '_', ' ', (string) ( $card['brand'] ?? __( 'Card' ) ) ) ) ), 'digits' => (string) $card['last_digits'] ] ) : null,
            'liability_shift'    => self::text( $meta['liability_shift'] ?? null ),
            'authorization'      => self::authorization( $meta ),
            'disputes'           => self::disputes( $meta ),
        ];
    }

    /**
     * A readable authorization status.
     *
     * @since 1.0.0
     *
     * @param  string  $status  PayPal authorization status.
     *
     * @return string
     */
    public static function authorizationStatus( string $status ): string
    {
        return match ( $status ) {
            'CREATED'            => __( 'Created' ),
            'CAPTURED'           => __( 'Captured' ),
            'DENIED'             => __( 'Denied' ),
            'PARTIALLY_CAPTURED' => __( 'Partially captured' ),
            'VOIDED'             => __( 'Voided' ),
            'EXPIRED'            => __( 'Expired' ),
            'PENDING'            => __( 'Pending' ),
            default              => self::headline( $status ),
        };
    }

    /**
     * A readable dispute status.
     *
     * @since 1.0.0
     *
     * @param  string  $status  PayPal dispute status.
     *
     * @return string
     */
    public static function disputeStatus( string $status ): string
    {
        return match ( $status ) {
            'OPEN'                        => __( 'Open' ),
            'WAITING_FOR_BUYER_RESPONSE'  => __( 'Waiting for the buyer' ),
            'WAITING_FOR_SELLER_RESPONSE' => __( 'Waiting for your response' ),
            'UNDER_REVIEW'                => __( 'Under review' ),
            'RESOLVED'                    => __( 'Resolved' ),
            'OTHER'                       => __( 'Other' ),
            default                       => self::headline( $status ),
        };
    }

    /**
     * A readable dispute reason.
     *
     * @since 1.0.0
     *
     * @param  string  $reason  PayPal dispute reason.
     *
     * @return string
     */
    public static function disputeReason( string $reason ): string
    {
        return match ( $reason ) {
            'MERCHANDISE_OR_SERVICE_NOT_RECEIVED'     => __( 'Item not received' ),
            'MERCHANDISE_OR_SERVICE_NOT_AS_DESCRIBED' => __( 'Item not as described' ),
            'UNAUTHORISED'                            => __( 'Unauthorized transaction' ),
            'CREDIT_NOT_PROCESSED'                    => __( 'Credit not processed' ),
            'DUPLICATE_TRANSACTION'                   => __( 'Duplicate transaction' ),
            'INCORRECT_AMOUNT'                        => __( 'Incorrect amount' ),
            'PAYMENT_BY_OTHER_MEANS'                  => __( 'Paid by other means' ),
            'CANCELED_RECURRING_BILLING'              => __( 'Canceled recurring billing' ),
            'PROBLEM_WITH_REMITTANCE'                 => __( 'Problem with remittance' ),
            'OTHER'                                   => __( 'Other' ),
            default                                   => self::headline( $reason ),
        };
    }

    /**
     * A readable dispute outcome.
     *
     * @since 1.0.0
     *
     * @param  string  $outcome  PayPal dispute outcome.
     *
     * @return string
     */
    public static function disputeOutcome( string $outcome ): string
    {
        // The same wording as the dispute note, so an outcome reads the
        // same in the panel and in the order's notes.
        return match ( $outcome ) {
            'RESOLVED_BUYER_FAVOUR', 'RESOLVED_SELLER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'CANCELED_BY_BUYER', 'ACCEPTED', 'DENIED' => Str::ucfirst( DisputeEventHandler::outcomeLabel( $outcome ) ),
            'NONE'                                                                                                               => __( 'None' ),
            default                                                                                                              => self::headline( $outcome ),
        };
    }

    /**
     * Whether the order was paid (or is being paid) through the PayPal
     * gateway family.
     *
     * @since 1.0.0
     *
     * @param  Order                 $order  Order.
     * @param  array<string, mixed>  $meta   `orders.meta.paypal`.
     *
     * @return bool
     */
    protected static function isPayPal( Order $order, array $meta ): bool
    {
        $keys = array_map( static fn ( string $gateway ): string => $gateway::KEY, EcommercePayPalServiceProvider::GATEWAYS );

        return in_array( (string) $order->getAttribute( 'payment_gateway_key' ), $keys, true ) && [] !== $meta;
    }

    /**
     * The authorization's status and expiry, or null without one.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $meta  `orders.meta.paypal`.
     *
     * @return array{status: string|null, status_label: string, expires_at: Carbon|null, expired: bool}|null
     */
    protected static function authorization( array $meta ): ?array
    {
        $authorization = $meta['authorization'] ?? null;

        if ( ! is_array( $authorization ) || ! isset( $authorization['id'] ) ) {
            return null;
        }

        $expiresAt = self::date( $authorization['expires_at'] ?? null );
        $status    = self::text( $authorization['status'] ?? null );

        return [
            'status'       => $status,
            'status_label' => null === $status ? __( 'Unknown' ) : self::authorizationStatus( $status ),
            'expires_at'   => $expiresAt,
            'expired'      => null !== $expiresAt && $expiresAt->isPast() && in_array( $status, [ null, 'CREATED', 'PENDING' ], true ),
        ];
    }

    /**
     * The order's disputes, most recently updated first.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed>  $meta  `orders.meta.paypal`.
     *
     * @return array<int, array{id: string, status: string|null, status_label: string, outcome: string|null, outcome_label: string|null, reason: string|null, reason_label: string|null, amount: string|null, respond_by: Carbon|null, url: string|null}>
     */
    protected static function disputes( array $meta ): array
    {
        $disputes = [];

        foreach ( (array) ( $meta['disputes'] ?? [] ) as $id => $dispute ) {
            if ( ! is_array( $dispute ) ) {
                continue;
            }

            $amount  = self::text( $dispute['amount'] ?? null );
            $status  = self::text( $dispute['status'] ?? null );
            $outcome = self::text( $dispute['outcome'] ?? null );
            $reason  = self::text( $dispute['reason'] ?? null );

            $disputes[] = [
                'id'            => (string) ( $dispute['id'] ?? $id ),
                'status'        => $status,
                'status_label'  => null === $status ? __( 'Unknown' ) : self::disputeStatus( $status ),
                'outcome'       => $outcome,
                'outcome_label' => null === $outcome ? null : self::disputeOutcome( $outcome ),
                'reason'        => $reason,
                'reason_label'  => null === $reason ? null : self::disputeReason( $reason ),
                'amount'        => null === $amount ? null : trim( $amount . ' ' . (string) ( $dispute['currency'] ?? '' ) ),
                'respond_by'    => self::date( $dispute['respond_by'] ?? null ),
                'url'           => self::text( $dispute['url'] ?? null ),
                'updated_at'    => (string) ( $dispute['updated_at'] ?? '' ),
            ];
        }

        usort( $disputes, static fn ( array $a, array $b ): int => strcmp( $b['updated_at'], $a['updated_at'] ) );

        return array_map( static fn ( array $dispute ): array => array_diff_key( $dispute, [ 'updated_at' => true ] ), $disputes );
    }

    /**
     * A readable funding source.
     *
     * @since 1.0.0
     *
     * @param  string|null  $source  `payment_source` key.
     *
     * @return string|null
     */
    protected static function fundingSource( ?string $source ): ?string
    {
        return match ( $source ) {
            null         => null,
            'paypal'     => __( 'PayPal' ),
            'venmo'      => __( 'Venmo' ),
            'card'       => __( 'Card' ),
            'apple_pay'  => __( 'Apple Pay' ),
            'google_pay' => __( 'Google Pay' ),
            default      => self::headline( $source ),
        };
    }

    /**
     * A value PayPal added after this release, made readable
     * (`NEW_STATUS` becomes "New Status").
     *
     * @since 1.0.0
     *
     * @param  string  $value  PayPal enum value.
     *
     * @return string
     */
    protected static function headline( string $value ): string
    {
        return Str::headline( strtolower( $value ) );
    }

    /**
     * A trimmed non-empty string, or null.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  Value.
     *
     * @return string|null
     */
    protected static function text( mixed $value ): ?string
    {
        return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : null;
    }

    /**
     * A date from meta, or null.
     *
     * @since 1.0.0
     *
     * @param  mixed  $value  ISO 8601 string.
     *
     * @return Carbon|null
     */
    protected static function date( mixed $value ): ?Carbon
    {
        if ( ! is_string( $value ) || '' === trim( $value ) ) {
            return null;
        }

        try {
            return Carbon::parse( $value );
        } catch ( Throwable ) {
            return null;
        }
    }

    /**
     * Requires `view` on the order (the engine's `order.view`).
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function authorizeView(): void
    {
        $order = Order::query()->findOrFail( $this->orderId );
        $user  = auth()->user();

        // The staff ability itself, not the order policy's "a shopper may
        // view their own order" shortcut: payer and PayPal details are for
        // the store's staff.
        abort_unless( null !== $user && app( EcommerceAuthorizer::class )->allows( $user, 'order', 'view', $order ), 403 );
    }
}
