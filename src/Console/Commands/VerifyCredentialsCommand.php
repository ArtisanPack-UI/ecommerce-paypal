<?php

/**
 * `ecommerce-paypal:verify-credentials`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Console\Commands;

use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Support\ApplePayDomainAssociation;
use ArtisanPackUI\EcommercePayPal\Support\PayPalCurrencies;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookEvents;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Checks that the PayPal setup works (spec §11): the mode, that the
 * credentials get a token, which methods are on and whether they take the
 * store's base currency, that the configured webhook ids exist at PayPal,
 * and that the Apple Pay domain association file is reachable.
 *
 * Exits non-zero when any check fails.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class VerifyCredentialsCommand extends Command
{
    /**
     * Where Apple fetches the domain association file.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const APPLE_PAY_DOMAIN_PATH = ApplePayDomainAssociation::PATH;

    /**
     * Method keys and their labels.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const METHODS = [ 'paypal', 'card', 'apple_pay', 'google_pay' ];

    /**
     * @var string
     */
    protected $signature = 'ecommerce-paypal:verify-credentials
        {--url= : The webhook URL PayPal should deliver to, when it is not the webhook route of this app (a proxy, say).}';

    /**
     * @var string
     */
    protected $description = 'Check the PayPal credentials, methods, currency, webhook, and Apple Pay domain file.';

    /**
     * Whether any check failed.
     *
     * @since 1.0.0
     *
     * @var bool
     */
    protected bool $failed = false;

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     * @param  HttpFactory   $http    HTTP client, for the Apple Pay file.
     *
     * @return int
     */
    public function handle( PayPalClient $client, HttpFactory $http ): int
    {
        $this->failed = false;

        $environment = $client->environment();

        $this->components->twoColumnDetail( __( 'Mode' ), $environment->isLive() ? __( 'Live' ) : __( 'Sandbox' ) );

        $authenticated = $this->checkToken( $client );

        $this->checkMethods();

        if ( $authenticated ) {
            $this->checkWebhooks( $client );
        } else {
            $this->result( __( 'Webhook' ), false, __( 'skipped: no access token' ) );
        }

        $this->checkApplePayDomain( $http );

        $this->newLine();

        if ( $this->failed ) {
            $this->components->error( __( 'Some PayPal checks failed.' ) );

            return self::FAILURE;
        }

        $this->components->info( __( 'PayPal is set up correctly.' ) );

        return self::SUCCESS;
    }

    /**
     * Requests an access token with the configured credentials.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     *
     * @return bool
     */
    protected function checkToken( PayPalClient $client ): bool
    {
        $credentials = $client->credentials();

        if ( ! $credentials->isComplete() ) {
            $this->result( __( 'Credentials' ), false, __( 'PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET must both be set' ) );

            return false;
        }

        try {
            $client->tokens()->fetch( $credentials );
        } catch ( PayPalApiException $exception ) {
            $this->result(
                __( 'Access token' ),
                false,
                $exception->isAuthenticationFailure()
                    ? __( 'PayPal refused the credentials for :mode mode', [ 'mode' => $credentials->environment->mode ] )
                    : __( 'PayPal could not be reached (:message)', [ 'message' => $exception->debugId ?? $exception->getMessage() ] ),
            );

            return false;
        }

        $this->result( __( 'Access token' ), true );

        return true;
    }

    /**
     * Lists the enabled methods and checks each takes the base currency.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function checkMethods(): void
    {
        $currency = strtoupper( (string) config( 'artisanpack.ecommerce.base_currency', 'USD' ) );
        $enabled  = array_values( array_filter(
            self::METHODS,
            static fn ( string $method ): bool => (bool) config( 'artisanpack.ecommerce-paypal.methods.' . $method . '.enabled', false ),
        ) );

        if ( [] === $enabled ) {
            $this->result( __( 'Payment methods' ), false, __( 'none enabled' ) );

            return;
        }

        $this->components->twoColumnDetail( __( 'Payment methods' ), implode( ', ', array_map( fn ( string $method ): string => $this->methodLabel( $method ), $enabled ) ) );

        foreach ( $enabled as $method ) {
            $this->result(
                __( ':method in :currency', [ 'method' => $this->methodLabel( $method ), 'currency' => $currency ] ),
                PayPalCurrencies::supports( $method, $currency ),
                PayPalCurrencies::supports( $method, $currency ) ? null : __( 'PayPal does not support :currency for this method', [ 'currency' => $currency ] ),
            );
        }
    }

    /**
     * Checks each configured webhook id exists at PayPal.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     *
     * @return void
     */
    protected function checkWebhooks( PayPalClient $client ): void
    {
        $ids = array_values( array_filter( array_map( 'trim', explode( ',', (string) config( 'artisanpack.ecommerce-paypal.webhook_id', '' ) ) ) ) );

        if ( [] === $ids ) {
            $this->result( __( 'Webhook' ), false, __( 'PAYPAL_WEBHOOK_ID is not set; run ecommerce-paypal:webhooks:register' ) );

            return;
        }

        foreach ( $ids as $id ) {
            try {
                $webhook = $client->webhooks()->get( $id );
            } catch ( PayPalApiException $exception ) {
                $this->result(
                    __( 'Webhook :id', [ 'id' => $id ] ),
                    false,
                    404 === $exception->status
                        ? __( 'not found at PayPal in :mode mode', [ 'mode' => $client->environment()->mode ] )
                        : __( 'could not be checked (:message)', [ 'message' => $exception->debugId ?? $exception->getMessage() ] ),
                );

                continue;
            }

            $expected = $this->expectedWebhookUrl();

            if ( rtrim( $webhook->url(), '/' ) !== rtrim( $expected, '/' ) ) {
                $this->result(
                    __( 'Webhook :id', [ 'id' => $id ] ),
                    false,
                    __( 'delivers to :url instead of :expected', [ 'url' => $webhook->url(), 'expected' => $expected ] ),
                );

                continue;
            }

            $missing = in_array( '*', $webhook->eventTypes(), true )
                ? []
                : array_values( array_diff( PayPalWebhookEvents::SUBSCRIBED, $webhook->eventTypes() ) );

            if ( [] !== $missing ) {
                $this->result(
                    __( 'Webhook :id', [ 'id' => $id ] ),
                    false,
                    __( 'is missing events: :events; run ecommerce-paypal:webhooks:register', [ 'events' => implode( ', ', $missing ) ] ),
                );

                continue;
            }

            $this->result( __( 'Webhook :id', [ 'id' => $id ] ), true, $webhook->url() );
        }
    }

    /**
     * The URL the webhook should deliver to: `--url`, or the PayPal route.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function expectedWebhookUrl(): string
    {
        $option = $this->option( 'url' );

        return is_string( $option ) && '' !== trim( $option )
            ? trim( $option )
            : route( 'ecommerce.webhooks', [ 'provider' => PayPalWalletGateway::KEY ] );
    }

    /**
     * Checks the Apple Pay domain association file is served, when Apple
     * Pay is on.
     *
     * @since 1.0.0
     *
     * @param  HttpFactory  $http  HTTP client.
     *
     * @return void
     */
    protected function checkApplePayDomain( HttpFactory $http ): void
    {
        if ( ! (bool) config( 'artisanpack.ecommerce-paypal.methods.apple_pay.enabled', false ) ) {
            $this->components->twoColumnDetail( __( 'Apple Pay domain file' ), __( 'skipped: Apple Pay is off' ) );

            return;
        }

        $url = $this->origin() . self::APPLE_PAY_DOMAIN_PATH;

        // Apple only fetches the file over HTTPS.
        if ( ! str_starts_with( $url, 'https://' ) ) {
            $this->result( __( 'Apple Pay domain file' ), false, __( 'Apple Pay needs the store served over HTTPS (APP_URL is :url)', [ 'url' => url( '/' ) ] ) );

            return;
        }

        try {
            // Apple doesn't follow redirects, so neither does the check.
            $response  = $http->timeout( 10 )->withoutRedirecting()->get( $url );
            $reachable = $response->successful() && $this->isDomainAssociation( $response->body(), (string) $response->header( 'Content-Type' ) );
        } catch ( Throwable ) {
            $reachable = false;
        }

        $this->result( __( 'Apple Pay domain file' ), $reachable, $reachable ? $url : __( ':url is not reachable', [ 'url' => $url ] ) );
    }

    /**
     * The app's origin (scheme, host, and port), without any APP_URL path:
     * Apple looks for the file at the domain root.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function origin(): string
    {
        $parts = parse_url( url( '/' ) );
        $port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

        return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'localhost' ) . $port;
    }

    /**
     * Whether a served body is the domain association file: the configured
     * contents when the setting holds them, otherwise any non-empty body
     * that isn't an HTML page (a catch-all route or an error page).
     *
     * @since 1.0.0
     *
     * @param  string  $body         Served body.
     * @param  string  $contentType  Served `Content-Type`.
     *
     * @return bool
     */
    protected function isDomainAssociation( string $body, string $contentType ): bool
    {
        $body = trim( $body );

        if ( '' === $body || str_contains( strtolower( $contentType ), 'html' ) || str_starts_with( $body, '<' ) ) {
            return false;
        }

        if ( 'contents' !== ApplePayDomainAssociation::source() ) {
            return ApplePayDomainAssociation::isAssociation( $body );
        }

        return trim( (string) config( 'artisanpack.ecommerce-paypal.methods.apple_pay.domain_association', '' ) ) === $body;
    }

    /**
     * Prints a check result and records failures.
     *
     * @since 1.0.0
     *
     * @param  string       $label   Check.
     * @param  bool         $passed  Whether it passed.
     * @param  string|null  $detail  Extra detail.
     *
     * @return void
     */
    protected function result( string $label, bool $passed, ?string $detail = null ): void
    {
        if ( ! $passed ) {
            $this->failed = true;
        }

        $status = $passed ? '<fg=green;options=bold>' . __( 'OK' ) . '</>' : '<fg=red;options=bold>' . __( 'FAIL' ) . '</>';

        $this->components->twoColumnDetail( $label, null === $detail ? $status : $detail . ' ' . $status );
    }

    /**
     * A method's translated label.
     *
     * @since 1.0.0
     *
     * @param  string  $method  Method key.
     *
     * @return string
     */
    protected function methodLabel( string $method ): string
    {
        return match ( $method ) {
            'paypal'     => __( 'PayPal' ),
            'card'       => __( 'Credit or debit card' ),
            'apple_pay'  => __( 'Apple Pay' ),
            'google_pay' => __( 'Google Pay' ),
            default      => $method,
        };
    }
}
