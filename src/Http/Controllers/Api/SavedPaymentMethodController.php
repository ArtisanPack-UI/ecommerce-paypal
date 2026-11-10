<?php

/**
 * Saved payment methods REST controller.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Http\Controllers\Api;

use ArtisanPackUI\Ecommerce\Auth\TokenAbilities;
use ArtisanPackUI\Ecommerce\Http\Support\Problem;
use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Http\Resources\SavedPaymentMethodResource;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `me/paypal/payment-methods` (spec §10.3): the signed-in shopper lists
 * their saved PayPal methods, removes one (at PayPal first, then here), and
 * picks the default.
 *
 * Routes sit on the engine's REST stack (`api_prefix`, `api.middleware`,
 * `api.auth_middleware`) with the same rate policy as the engine's own
 * `me/*` routes, and writes require an `Idempotency-Key`. A method is only
 * ever looked up among the shopper's own, so anyone else's is a `404`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class SavedPaymentMethodController
{
    /**
     * Creates the controller.
     *
     * @since 1.0.0
     *
     * @param  PayPalVault  $vault  Vault.
     */
    public function __construct( protected PayPalVault $vault )
    {
    }

    /**
     * Lists the shopper's saved methods for the current mode: the default
     * first, then the most recently used.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     *
     * @return JsonResponse
     */
    public function index( Request $request ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => SavedPaymentMethodResource::collection(
            $this->vault->tokensFor( (int) $customer->getKey() ),
        )->response( $request ) );
    }

    /**
     * Removes a saved method at PayPal, then here. When PayPal can't be
     * reached, nothing is removed and the shopper can try again.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Local method id.
     *
     * @return JsonResponse|Response
     */
    public function destroy( Request $request, string $token ): JsonResponse|Response
    {
        return $this->forCustomer( $request, function ( Customer $customer ) use ( $request, $token ): JsonResponse|Response {
            $method = $this->owned( $customer, $token );

            try {
                $this->vault->remove( $method );
            } catch ( PayPalApiException $exception ) {
                report( $exception );

                return Problem::make( 502, 'paypal-unavailable', __( 'PayPal unavailable' ), __( 'We couldn\'t remove this payment method at PayPal. Try again in a moment.' ), $request );
            }

            return response()->noContent();
        } );
    }

    /**
     * Makes a saved method the shopper's default.
     *
     * @since 1.0.0
     *
     * @param  Request  $request  Request.
     * @param  string   $token    Local method id.
     *
     * @return JsonResponse
     */
    public function makeDefault( Request $request, string $token ): JsonResponse
    {
        return $this->forCustomer( $request, fn ( Customer $customer ): JsonResponse => ( new SavedPaymentMethodResource(
            $this->vault->makeDefault( $this->owned( $customer, $token ) ),
        ) )->response( $request ) );
    }

    /**
     * Runs `$callback` for the signed-in shopper's customer record, as the
     * engine's `me/*` routes do: a token without the storefront ability is
     * a `403`, and an account with no customer record a `404`.
     *
     * @since 1.0.0
     *
     * @param  Request                                        $request   Request.
     * @param  Closure(Customer): (JsonResponse|Response)  $callback  Operation.
     *
     * @return JsonResponse|Response
     */
    protected function forCustomer( Request $request, Closure $callback ): JsonResponse|Response
    {
        $user = $request->user();

        if ( null !== $user && ! TokenAbilities::allowsStorefront( $user ) ) {
            return Problem::make( 403, 'forbidden', __( 'Forbidden' ), __( 'Missing ability :ability.', [ 'ability' => TokenAbilities::STOREFRONT ] ), $request );
        }

        $customer = null === $user ? null : Customer::forUser( $user );

        if ( null === $customer ) {
            return Problem::make( 404, 'customer-not-found', __( 'Not found' ), __( 'No customer record is linked to this account.' ), $request );
        }

        return $callback( $customer );
    }

    /**
     * One of the customer's own methods in the current mode, or a `404`.
     *
     * @since 1.0.0
     *
     * @param  Customer  $customer  Customer.
     * @param  string    $token     Local method id.
     *
     * @return PayPalPaymentToken
     */
    protected function owned( Customer $customer, string $token ): PayPalPaymentToken
    {
        $method = $this->vault->tokenFor( (int) $customer->getKey(), $token );

        abort_if( null === $method, 404 );

        return $method;
    }
}
