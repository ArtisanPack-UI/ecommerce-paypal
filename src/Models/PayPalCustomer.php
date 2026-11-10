<?php

/**
 * PayPal vault customer model.
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
use ArtisanPackUI\EcommercePayPal\Database\Factories\PayPalCustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The PayPal vault customer id of an engine customer, per mode (spec §10.1).
 *
 * PayPal returns the id with the first saved payment method; later Orders
 * send it as `attributes.customer.id`, and it is what an `id_token` is
 * minted for.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 *
 * @property int         $id
 * @property int         $customer_id
 * @property string      $paypal_customer_id
 * @property string      $mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Customer|null $customer
 */
class PayPalCustomer extends Model
{
    use HasFactory;

    /**
     * @since 1.0.0
     *
     * @var string
     */
    protected $table = 'ecommerce_paypal_customers';

    /**
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'customer_id',
        'paypal_customer_id',
        'mode',
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
     * The customer's saved payment methods, in every mode; chain
     * `->forMode( $customer->mode )` for this record's mode. (The relation
     * can't filter by mode itself and still eager load.)
     *
     * @since 1.0.0
     *
     * @return HasMany<PayPalPaymentToken, $this>
     */
    public function paymentTokens(): HasMany
    {
        return $this->hasMany( PayPalPaymentToken::class, 'customer_id', 'customer_id' );
    }

    /**
     * Limits to `$mode` (`sandbox` or `live`).
     *
     * @since 1.0.0
     *
     * @param  Builder<PayPalCustomer>  $query  Query.
     * @param  string                   $mode   Mode.
     *
     * @return void
     */
    public function scopeForMode( Builder $query, string $mode ): void
    {
        $query->where( 'mode', $mode );
    }

    /**
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
        ];
    }

    /**
     * @since 1.0.0
     *
     * @return PayPalCustomerFactory
     */
    protected static function newFactory(): PayPalCustomerFactory
    {
        return PayPalCustomerFactory::new();
    }
}
