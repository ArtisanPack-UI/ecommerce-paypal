<?php

/**
 * Creates the `ecommerce_paypal_customers` table (spec §10.1).
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
     * Maps an engine customer to their PayPal vault customer id, per mode.
     *
     * `customer_id` is indexed but has no foreign key: the engine deletes
     * the customer row inside its anonymizing transaction and then fires
     * `ap.ecommerce.customer.deleted`, whose listener erases these rows
     * (and the tokens at PayPal). A cascade would remove the rows before
     * the listener could read them.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'ecommerce_paypal_customers', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->unsignedBigInteger( 'customer_id' );
            $table->string( 'paypal_customer_id', 64 );
            $table->string( 'mode', 10 );
            $table->timestamps();

            $table->unique( [ 'customer_id', 'mode' ], 'ecommerce_paypal_customers_customer_mode_unique' );
            $table->unique( [ 'paypal_customer_id', 'mode' ], 'ecommerce_paypal_customers_paypal_mode_unique' );
        } );
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'ecommerce_paypal_customers' );
    }
};
