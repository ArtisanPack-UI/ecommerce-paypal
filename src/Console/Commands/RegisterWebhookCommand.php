<?php

/**
 * `ecommerce-paypal:webhooks:register`.
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

use ArtisanPackUI\EcommercePayPal\Api\Data\PayPalWebhook;
use ArtisanPackUI\EcommercePayPal\Api\PayPalClient;
use ArtisanPackUI\EcommercePayPal\Exceptions\PayPalApiException;
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalWalletGateway;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookEvents;
use ArtisanPackUI\EcommercePayPal\Webhooks\WebhookVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Subscribes the store's PayPal webhook endpoint to exactly the events in
 * spec §8.2, creating the webhook or updating the one already registered
 * for the URL, and prints the id to set as `PAYPAL_WEBHOOK_ID`.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
class RegisterWebhookCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ecommerce-paypal:webhooks:register
        {--url= : Register this URL instead of the store\'s ecommerce/webhooks/paypal route.}';

    /**
     * @var string
     */
    protected $description = 'Create or update the PayPal webhook with the events the gateway handles.';

    /**
     * Runs the command.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     *
     * @return int
     */
    public function handle( PayPalClient $client ): int
    {
        $url  = $this->webhookUrl();
        $live = $client->environment()->isLive();

        if ( null === $url ) {
            $this->components->error( __( 'The webhook URL is not a valid URL.' ) );

            return self::FAILURE;
        }

        if ( $live && 'https' !== strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) ) {
            $this->components->error( __( 'Live webhooks must use HTTPS. Set APP_URL to your HTTPS address, or pass --url.' ) );

            return self::FAILURE;
        }

        if ( ! $client->credentials()->isComplete() ) {
            $this->components->error( __( 'Set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET first.' ) );

            return self::FAILURE;
        }

        try {
            $existing = $this->existing( $client, $url );
            $webhook  = null === $existing
                ? $client->webhooks()->create( $url, PayPalWebhookEvents::SUBSCRIBED, (string) Str::uuid() )
                : $client->webhooks()->update( $existing->id(), PayPalWebhookEvents::SUBSCRIBED, (string) Str::uuid(), $existing->url() === $url ? null : $url );
        } catch ( PayPalApiException $exception ) {
            $this->components->error( __( 'PayPal refused the webhook: :message', [ 'message' => $exception->getMessage() ] ) );

            if ( null !== $exception->debugId ) {
                $this->components->twoColumnDetail( __( 'PayPal debug id' ), $exception->debugId );
            }

            return self::FAILURE;
        }

        $this->components->info( null === $existing
            ? __( 'Created the PayPal webhook for :url.', [ 'url' => $url ] )
            : __( 'Updated the events of the PayPal webhook for :url.', [ 'url' => $url ] ) );

        $this->components->twoColumnDetail( __( 'Mode' ), $live ? __( 'Live' ) : __( 'Sandbox' ) );
        $this->components->twoColumnDetail( __( 'Webhook ID' ), $webhook->id() );
        $this->components->twoColumnDetail( __( 'Events' ), (string) count( PayPalWebhookEvents::SUBSCRIBED ) );

        $this->newLine();
        $this->line( '    PAYPAL_WEBHOOK_ID=' . $webhook->id() );
        $this->newLine();

        if ( ! in_array( $webhook->id(), WebhookVerifier::webhookIds(), true ) ) {
            $this->components->warn( __( 'Set PAYPAL_WEBHOOK_ID to this id, or webhooks will be rejected.' ) );
        }

        return self::SUCCESS;
    }

    /**
     * The URL to register.
     *
     * @since 1.0.0
     *
     * @return string|null
     */
    protected function webhookUrl(): ?string
    {
        $option = $this->option( 'url' );
        $url    = is_string( $option ) && '' !== trim( $option )
            ? trim( $option )
            : route( 'ecommerce.webhooks', [ 'provider' => PayPalWalletGateway::KEY ] );

        return false === filter_var( $url, FILTER_VALIDATE_URL ) ? null : $url;
    }

    /**
     * The webhook already registered for `$url`, or null.
     *
     * @since 1.0.0
     *
     * @param  PayPalClient  $client  API client.
     * @param  string        $url     Webhook URL.
     *
     * @throws PayPalApiException On failure.
     *
     * @return PayPalWebhook|null
     */
    protected function existing( PayPalClient $client, string $url ): ?PayPalWebhook
    {
        foreach ( $client->webhooks()->list() as $webhook ) {
            if ( rtrim( $webhook->url(), '/' ) === rtrim( $url, '/' ) ) {
                return $webhook;
            }
        }

        return null;
    }
}
