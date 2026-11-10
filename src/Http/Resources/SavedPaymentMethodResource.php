<?php

/**
 * Saved payment method API resource.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Http\Resources;

use ArtisanPackUI\Ecommerce\Http\Resources\EcommerceResource;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use Illuminate\Http\Request;

/**
 * A shopper's saved PayPal payment method (spec §10.3), for the React and
 * Vue storefronts.
 *
 * Carries what the shopper needs to recognize the method and nothing
 * PayPal could charge with: the PayPal token id stays on the server, and
 * the method is addressed by its local `id`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 *
 * @property PayPalPaymentToken $resource
 */
class SavedPaymentMethodResource extends EcommerceResource
{
    /**
     * The resource name (`type`).
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const NAME = 'paypal_payment_method';

    /**
     * The method's fields.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return array<string, mixed>
     */
    protected function fields( Request $request ): array
    {
        $token   = $this->resource;
        $display = (array) ( $token->display ?? [] );

        return [
            'method'       => $token->type,
            'gateway'      => $token->gateway_key,
            'label'        => $token->label(),
            'brand'        => $display['brand'] ?? null,
            'last_digits'  => $display['last_digits'] ?? null,
            'expiry'       => $display['expiry'] ?? null,
            'expired'      => $token->isExpired(),
            'email'        => $display['email'] ?? null,
            'is_default'   => (bool) $token->is_default,
            'mode'         => $token->mode,
            'last_used_at' => $token->last_used_at,
            'created_at'   => $token->created_at,
        ];
    }
}
