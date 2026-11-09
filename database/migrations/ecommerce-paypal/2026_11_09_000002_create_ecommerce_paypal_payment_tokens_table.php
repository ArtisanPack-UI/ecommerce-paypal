<?php

/**
 * Creates the `ecommerce_paypal_payment_tokens` table (spec §10.1).
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Vaulted payment methods: PayPal's token id and display data only
     * (brand, last digits, expiry, PayPal email). Never card numbers,
     * security codes, or track data.
     *
     * `customer_id` has no foreign key, for the reason given on
     * `ecommerce_paypal_customers`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'ecommerce_paypal_payment_tokens', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->string( 'paypal_token_id', 64 );
            $table->string( 'gateway_key', 40 );
            $table->string( 'type', 20 );
            $table->json( 'display' )->nullable();
            $table->boolean( 'is_default' )->default( false );
            $table->string( 'mode', 10 );
            $table->timestamp( 'last_used_at' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique( [ 'paypal_token_id', 'mode' ], 'ecommerce_paypal_payment_tokens_token_mode_unique' );
            $table->index( [ 'customer_id', 'mode' ], 'ecommerce_paypal_payment_tokens_customer_mode_idx' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_paypal_payment_tokens' );
    }
};
