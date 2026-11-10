<?php

/**
 * PayPal vault payment token model.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Models;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Database\Factories\PayPalPaymentTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A payment method a customer saved in PayPal's vault (spec §10.1, D8).
 *
 * Holds PayPal's token id and what a shopper needs to recognize the method
 * (`display`: `brand`, `last_digits`, `expiry`, `email`), never card data.
 * Defaults are kept to one per customer per mode by
 * {@see \ArtisanPackUI\EcommercePayPal\Vault\PayPalVault::makeDefault()}.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 *
 * @property int                       $id
 * @property int                       $customer_id
 * @property string                    $paypal_token_id
 * @property string                    $gateway_key
 * @property string                    $type
 * @property array<string, string>|null $display
 * @property bool                      $is_default
 * @property string                    $mode
 * @property Carbon|null               $last_used_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property Carbon|null               $deleted_at
 * @property Customer|null             $customer
 */
class PayPalPaymentToken extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The token types (`payment_source` keys PayPal vaults).
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const TYPES = [ 'paypal', 'card', 'venmo' ];

    /**
     * The display keys kept.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const DISPLAY_KEYS = [ 'brand', 'last_digits', 'expiry', 'email' ];

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_paypal_payment_tokens';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'paypal_token_id',
        'gateway_key',
        'type',
        'display',
        'is_default',
        'mode',
        'last_used_at',
    ];

    /**
     * @since 1.0.0
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * The engine customer.
     *
     * @since 1.0.0
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo( Customer::class );
    }

    /**
     * What the shopper sees for this method: "Visa ending in 1111",
     * "PayPal (ana@example.com)", or "Venmo".
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function label(): string
    {
        $display = (array) ( $this->display ?? [] );

        if ( 'card' === $this->type ) {
            $brand = isset( $display['brand'] ) ? ucwords( strtolower( str_replace( '_', ' ', (string) $display['brand'] ) ) ) : __( 'Card' );

            return isset( $display['last_digits'] )
                ? __( ':brand ending in :digits', [ 'brand' => $brand, 'digits' => $display['last_digits'] ] )
                : $brand;
        }

        $name = 'venmo' === $this->type ? __( 'Venmo' ) : __( 'PayPal' );

        return isset( $display['email'] ) ? __( ':method (:email)', [ 'method' => $name, 'email' => $display['email'] ] ) : $name;
    }

    /**
     * The card's expiry as "MM/YYYY", or null (wallets, or PayPal didn't
     * say).
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    public function expiry(): ?string
    {
        $expiry = $this->display['expiry'] ?? null;

        if ( ! is_string( $expiry ) || 1 !== preg_match( '/^(\d{4})-(\d{2})$/', $expiry, $parts ) ) {
            return null;
        }

        return $parts[2] . '/' . $parts[1];
    }

    /**
     * Whether the card's expiry month has passed.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        $expiry = $this->display['expiry'] ?? null;

        if ( ! is_string( $expiry ) || 1 !== preg_match( '/^\d{4}-\d{2}$/', $expiry ) ) {
            return false;
        }

        return Carbon::createFromFormat( '!Y-m', $expiry )->endOfMonth()->isPast();
    }

    /**
     * Limits to `$mode` (`sandbox` or `live`).
     *
     * @since 1.0.0
     *
     * @param  Builder<PayPalPaymentToken>  $query  Query.
     * @param  string                       $mode   Mode.
     *
     * @return void
     */
    public function scopeForMode( Builder $query, string $mode ): void
    {
        $query->where( 'mode', $mode );
    }

    /**
     * Limits to `$customerId`'s tokens.
     *
     * @since 1.0.0
     *
     * @param  Builder<PayPalPaymentToken>  $query       Query.
     * @param  int                          $customerId  Engine customer id.
     *
     * @return void
     */
    public function scopeForCustomer( Builder $query, int $customerId ): void
    {
        $query->where( 'customer_id', $customerId );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id'  => 'integer',
            'display'      => 'array',
            'is_default'   => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return PayPalPaymentTokenFactory
     */
    protected static function newFactory(): PayPalPaymentTokenFactory
    {
        return PayPalPaymentTokenFactory::new();
    }
}
