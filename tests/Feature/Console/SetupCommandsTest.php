<?php

declare( strict_types=1 );

use ArtisanPackUI\EcommercePayPal\Console\Commands\InstallCommand;
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;
use ArtisanPackUI\EcommercePayPal\Webhooks\PayPalWebhookEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.client_id'     => 'client-id',
        'artisanpack.ecommerce-paypal.client_secret' => 'client-secret',
        'artisanpack.ecommerce-paypal.mode'          => 'sandbox',
        'artisanpack.ecommerce-paypal.webhook_id'    => '0EH40505U7160970P',
        'artisanpack.ecommerce.base_currency'        => 'USD',
        'app.url'                                    => 'https://shop.test',
    ] );

    url()->forceRootUrl( 'https://shop.test' );
    url()->forceScheme( 'https' );

    Http::preventStrayRequests();
    Sleep::fake();

    $this->paypal = PayPalFake::fake();
} );

describe( 'install', function (): void {
    afterEach( function (): void {
        File::delete( config_path( InstallCommand::CONFIG_FILE ) );
    } );

    it( 'publishes the config and assets and prints the setup lines', function (): void {
        $this->artisan( 'ecommerce-paypal:install', [ '--no-interaction' => true ] )
            ->doesntExpectOutputToContain( 'Overwrite it?' )
            ->expectsOutputToContain( 'ecommerce-paypal/resources/views/**/*.blade.php' )
            ->expectsOutputToContain( 'script-src https://www.paypal.com https://*.paypal.com https://applepay.cdn-apple.com https://pay.google.com' )
            ->expectsOutputToContain( 'frame-src https://*.paypal.com https://pay.google.com' )
            ->expectsOutputToContain( 'connect-src https://*.paypal.com' )
            ->expectsOutputToContain( 'img-src https://*.paypalobjects.com' )
            ->expectsOutputToContain( 'Skipped the migrations' )
            ->assertSuccessful();

        expect( config_path( InstallCommand::CONFIG_FILE ) )->toBeFile();
    } );

    it( 'keeps an existing config unless told to overwrite it', function (): void {
        File::ensureDirectoryExists( dirname( config_path( InstallCommand::CONFIG_FILE ) ) );
        File::put( config_path( InstallCommand::CONFIG_FILE ), '<?php return [ "custom" => true ];' );

        $this->artisan( 'ecommerce-paypal:install' )
            ->expectsConfirmation( 'config/' . InstallCommand::CONFIG_FILE . ' already exists. Overwrite it?', 'no' )
            ->expectsConfirmation( 'Publish and run the PayPal migrations now?', 'no' )
            ->expectsOutputToContain( 'Kept your existing config file.' )
            ->assertSuccessful();

        expect( File::get( config_path( InstallCommand::CONFIG_FILE ) ) )->toContain( 'custom' );

        $this->artisan( 'ecommerce-paypal:install', [ '--force' => true, '--no-interaction' => true ] )->assertSuccessful();

        expect( File::get( config_path( InstallCommand::CONFIG_FILE ) ) )->not->toContain( 'custom' );
    } );

    it( 'publishes and runs the migrations on request', function (): void {
        $this->artisan( 'ecommerce-paypal:install', [ '--migrate' => true, '--no-interaction' => true ] )
            ->doesntExpectOutputToContain( 'Skipped the migrations' )
            ->assertSuccessful();
    } );
} );

describe( 'verify-credentials', function (): void {
    beforeEach( function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.paypal.enabled' => true, 'artisanpack.ecommerce-paypal.methods.card.enabled' => true ] );
    } );

    it( 'passes when everything checks out', function (): void {
        $this->artisan( 'ecommerce-paypal:verify-credentials' )
            ->expectsOutputToContain( 'Sandbox' )
            ->expectsOutputToContain( 'Access token' )
            ->expectsOutputToContain( 'PayPal, Credit or debit card' )
            ->expectsOutputToContain( 'Webhook 0EH40505U7160970P' )
            ->expectsOutputToContain( 'skipped: Apple Pay is off' )
            ->expectsOutputToContain( 'PayPal is set up correctly.' )
            ->assertSuccessful();
    } );

    it( 'fails without credentials', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.client_secret' => null ] );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )
            ->expectsOutputToContain( 'must both be set' )
            ->assertFailed();

        $this->paypal->assertNotSent( 'oauth.token' );
    } );

    it( 'fails when PayPal refuses the credentials', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.mode' => 'live' ] );
        $this->paypal->error( 'oauth.token', 'invalid-client' );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )
            ->expectsOutputToContain( 'Live' )
            ->expectsOutputToContain( 'refused the credentials for live mode' )
            ->expectsOutputToContain( 'skipped: no access token' )
            ->assertFailed();
    } );

    it( 'fails when a method does not take the base currency', function (): void {
        config( [ 'artisanpack.ecommerce.base_currency' => 'BRL' ] );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )
            ->expectsOutputToContain( 'PayPal does not support BRL for this method' )
            ->assertFailed();
    } );

    it( 'fails when no method is enabled', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.paypal.enabled' => false, 'artisanpack.ecommerce-paypal.methods.card.enabled' => false ] );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )->expectsOutputToContain( 'none enabled' )->assertFailed();
    } );

    it( 'fails without a webhook id', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => null ] );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )->expectsOutputToContain( 'PAYPAL_WEBHOOK_ID is not set' )->assertFailed();
    } );

    it( 'fails when a webhook id does not exist at PayPal', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => '0EH40505U7160970P, WH-OLD' ] );
        $this->paypal->respond( 'webhooks.get', 'webhooks/webhook' );
        $this->paypal->error( 'webhooks.get', 'resource-not-found' );

        $this->artisan( 'ecommerce-paypal:verify-credentials' )
            ->expectsOutputToContain( 'not found at PayPal in sandbox mode' )
            ->assertFailed();

        $this->paypal->assertSentCount( 'webhooks.get', 2 );
    } );

    it( 'checks the Apple Pay domain file when Apple Pay is on', function ( int $status, string $body, bool $passes ): void {
        config( [ 'artisanpack.ecommerce-paypal.methods.apple_pay.enabled' => true ] );

        Http::fake( [ 'https://shop.test/.well-known/apple-developer-merchantid-domain-association' => Http::response( $body, $status ) ] );

        $command = $this->artisan( 'ecommerce-paypal:verify-credentials' );

        $passes
            ? $command->expectsOutputToContain( 'PayPal is set up correctly.' )->assertSuccessful()
            : $command->expectsOutputToContain( 'is not reachable' )->assertFailed();
    } )->with( [
        'served'  => [ 200, 'apple-association-file', true ],
        'missing' => [ 404, '', false ],
        'empty'   => [ 200, '', false ],
    ] );
} );

describe( 'webhooks:register', function (): void {
    it( 'creates the webhook with the spec event list', function (): void {
        $this->paypal->respond( 'webhooks.list', [ 'webhooks' => [] ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register' )
            ->expectsOutputToContain( 'Created the PayPal webhook for https://shop.test/ecommerce/webhooks/paypal.' )
            ->expectsOutputToContain( 'PAYPAL_WEBHOOK_ID=0EH40505U7160970P' )
            ->assertSuccessful();

        $this->paypal->assertSent( 'webhooks.create', fn ( Request $request ): bool => 'https://shop.test/ecommerce/webhooks/paypal' === $request['url']
            && PayPalWebhookEvents::SUBSCRIBED === array_column( $request['event_types'], 'name' )
            && $request->hasHeader( 'PayPal-Request-Id' ) );
        $this->paypal->assertNotSent( 'webhooks.update' );
    } );

    it( 'updates the events of the webhook already registered for the URL', function (): void {
        $this->paypal->respond( 'webhooks.list', [ 'webhooks' => [ [ 'id' => 'WH-EXISTING', 'url' => 'https://shop.test/ecommerce/webhooks/paypal/', 'event_types' => [] ] ] ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register' )
            ->expectsOutputToContain( 'Updated the events of the PayPal webhook' )
            ->assertSuccessful();

        $this->paypal->assertSent( 'webhooks.update', fn ( Request $request ): bool => str_ends_with( $request->url(), '/WH-EXISTING' )
            && PayPalWebhookEvents::SUBSCRIBED === array_column( $request->data()[0]['value'], 'name' ) );
        $this->paypal->assertNotSent( 'webhooks.create' );
    } );

    it( 'warns when the printed id is not configured yet', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.webhook_id' => null ] );
        $this->paypal->respond( 'webhooks.list', [ 'webhooks' => [] ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register' )
            ->expectsOutputToContain( 'Set PAYPAL_WEBHOOK_ID to this id' )
            ->assertSuccessful();
    } );

    it( 'registers a custom URL', function (): void {
        $this->paypal->respond( 'webhooks.list', [ 'webhooks' => [] ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register', [ '--url' => 'https://tunnel.example/ecommerce/webhooks/paypal' ] )->assertSuccessful();

        $this->paypal->assertSent( 'webhooks.create', fn ( Request $request ): bool => 'https://tunnel.example/ecommerce/webhooks/paypal' === $request['url'] );
    } );

    it( 'refuses a non-HTTPS URL in live mode', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.mode' => 'live' ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register', [ '--url' => 'http://shop.test/ecommerce/webhooks/paypal' ] )
            ->expectsOutputToContain( 'Live webhooks must use HTTPS' )
            ->assertFailed();

        $this->paypal->assertNotSent( 'webhooks.create' );
    } );

    it( 'refuses an invalid URL', function (): void {
        $this->artisan( 'ecommerce-paypal:webhooks:register', [ '--url' => 'not a url' ] )
            ->expectsOutputToContain( 'not a valid URL' )
            ->assertFailed();
    } );

    it( 'fails without credentials', function (): void {
        config( [ 'artisanpack.ecommerce-paypal.client_id' => null ] );

        $this->artisan( 'ecommerce-paypal:webhooks:register' )->expectsOutputToContain( 'Set PAYPAL_CLIENT_ID' )->assertFailed();
    } );

    it( 'reports PayPal refusing the webhook with its debug id', function (): void {
        $this->paypal->respond( 'webhooks.list', [ 'webhooks' => [] ] );
        $this->paypal->error( 'webhooks.create', 'webhook-url-already-exists' );

        $this->artisan( 'ecommerce-paypal:webhooks:register' )
            ->expectsOutputToContain( 'Webhook URL already exists.' )
            ->expectsOutputToContain( PayPalFake::DEBUG_ID )
            ->assertFailed();
    } );
} );
