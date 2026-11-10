<?php

/**
 * Account saved payment methods component.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Livewire\Account;

use ArtisanPackUI\Ecommerce\Models\Customer;
use ArtisanPackUI\Ecommerce\RateLimiting\RateLimitSubject;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Models\PayPalPaymentToken;
use ArtisanPackUI\EcommercePayPal\Vault\PayPalVault;
use ArtisanPackUI\EcommerceStorefrontLivewire\Livewire\Concerns\RateLimitsStorefront;
use ArtisanPackUI\EcommerceStorefrontLivewire\Livewire\Concerns\SendsToasts;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * `<livewire:artisanpack-ecommerce-paypal-saved-methods />`
 *
 * The shopper's saved PayPal payment methods (spec §10.3): every card,
 * PayPal wallet, and Venmo account they saved in the current mode, the
 * default first. They can make one the default, or delete one after
 * confirming; deleting removes it at PayPal first, so a method PayPal still
 * holds never disappears only from this list.
 *
 * Every action looks the method up among the signed-in shopper's own
 * ({@see PayPalVault::tokenFor()}); anyone else's is a `404`. Writes count
 * against the storefront's shopper-write rate policy, as the address book's
 * do.
 *
 * Shown on the storefront account page `account/payment-methods` and in
 * the account menu (`AccountMenuRegistry`) while the PayPal satellite is
 * active.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class SavedPaymentMethods extends Component
{
    use RateLimitsStorefront;
    use SendsToasts;

    /**
     * The Livewire component name.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const COMPONENT = 'artisanpack-ecommerce-paypal-saved-methods';

    /**
     * The engine rate-limit policy writes count against.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const RATE_LIMIT_POLICY = 'ecommerce.cart.mutate';

    /**
     * Whether the delete confirmation is open.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    public bool $confirmingDelete = false;

    /**
     * The method waiting for the delete confirmation.
     *
     * @since 1.0.0
     *
     * @var int|null
     */
    #[Locked]
    public ?int $deletingId = null;

    /**
     * Counts deletions, to key the focus hand-off after each.
     *
     * @since 1.0.0
     *
     * @var int
     */
    #[Locked]
    public int $deletions = 0;

    /**
     * Asks to confirm deleting one of the shopper's methods.
     *
     * @since 1.0.0
     *
     * @param  int  $id  Method id.
     *
     * @return void
     */
    public function confirmDelete( int $id ): void
    {
        $this->deletingId       = (int) $this->owned( $id )->getKey();
        $this->confirmingDelete = true;
    }

    /**
     * Deletes the confirmed method at PayPal, then here.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function delete(): void
    {
        $id = $this->deletingId;

        $this->confirmingDelete = false;
        $this->deletingId       = null;

        if ( null === $id ) {
            return;
        }

        $method = $this->owned( $id );

        $deleted = $this->rateLimited( self::RATE_LIMIT_POLICY, function () use ( $method ): bool {
            try {
                app( PayPalVault::class )->remove( $method );
            } catch ( PayPalApiException $exception ) {
                report( $exception );

                $this->toastError( __( 'We couldn\'t remove this payment method' ), __( 'PayPal couldn\'t be reached. Try again in a moment.' ) );

                return false;
            } catch ( Throwable $exception ) {
                report( $exception );

                $this->toastError( __( 'We couldn\'t remove this payment method' ), __( 'Try again in a moment.' ) );

                return false;
            }

            return true;
        }, $this->rateLimitSubject() );

        if ( true === $deleted ) {
            $this->deletions++;
            $this->toastSuccess( __( 'Payment method removed' ) );
        }
    }

    /**
     * Makes one of the shopper's methods their default.
     *
     * @since 1.0.0
     *
     * @param  int  $id  Method id.
     *
     * @return void
     */
    public function makeDefault( int $id ): void
    {
        $method = $this->owned( $id );

        $updated = $this->rateLimited( self::RATE_LIMIT_POLICY, function () use ( $method ): bool {
            try {
                app( PayPalVault::class )->makeDefault( $method );
            } catch ( Throwable $exception ) {
                report( $exception );

                $this->toastError( __( 'We couldn\'t change your default payment method' ), __( 'Try again in a moment.' ) );

                return false;
            }

            return true;
        }, $this->rateLimitSubject() );

        if ( true === $updated ) {
            $this->toastSuccess( __( 'Default payment method updated' ) );
        }
    }

    /**
     * Renders the component.
     *
     * @since 1.0.0
     *
     * @return View
     */
    public function render(): View
    {
        $customer = $this->customer();
        $methods  = [];
        $failed   = false;

        if ( null !== $customer ) {
            try {
                $methods = app( PayPalVault::class )->tokensFor( (int) $customer->getKey() )
                    ->map( static fn ( PayPalPaymentToken $method ): array => [
                        'id'      => (int) $method->getKey(),
                        'type'    => $method->type,
                        'label'   => $method->label(),
                        'expiry'  => $method->expiry(),
                        'expired' => $method->isExpired(),
                        'default' => (bool) $method->is_default,
                    ] )
                    ->all();
            } catch ( Throwable $exception ) {
                report( $exception );

                $failed = true;
            }
        }

        $deleting = null === $this->deletingId ? null : collect( $methods )->firstWhere( 'id', $this->deletingId );

        return view( 'ecommerce-paypal::livewire.account.saved-methods', [
            'methods'       => $methods,
            'failed'        => $failed,
            'deletingLabel' => $deleting['label'] ?? null,
        ] );
    }

    /**
     * The signed-in shopper's customer record, or null.
     *
     * @since 1.0.0
     *
     * @return Customer|null
     */
    protected function customer(): ?Customer
    {
        $user = auth()->user();

        return null === $user ? null : Customer::forUser( $user );
    }

    /**
     * One of the shopper's methods; anything else is a `404`.
     *
     * @since 1.0.0
     *
     * @param  int  $id  Method id.
     *
     * @return PayPalPaymentToken
     */
    protected function owned( int $id ): PayPalPaymentToken
    {
        $customer = $this->customer();
        $method   = null === $customer ? null : app( PayPalVault::class )->tokenFor( (int) $customer->getKey(), $id );

        abort_if( null === $method, 404 );

        return $method;
    }

    /**
     * Who a write counts against: the signed-in shopper and their IP.
     *
     * @since 1.0.0
     *
     * @return RateLimitSubject
     */
    protected function rateLimitSubject(): RateLimitSubject
    {
        $ip   = request()->ip() ?? '127.0.0.1';
        $user = auth()->user();

        return null === $user ? RateLimitSubject::ip( $ip ) : RateLimitSubject::customer( $user, $ip );
    }
}
