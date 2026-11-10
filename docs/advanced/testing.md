---
title: Testing Your Application
---

# Testing Your Application

`ArtisanPackUI\EcommercePayPal\Testing\PayPalFake` stands in for PayPal's REST API in your own tests. The client is built on Laravel's HTTP client, so the fake works through `Http::fake()`: no network, no sandbox account.

## Set up

```php
use ArtisanPackUI\EcommercePayPal\Testing\PayPalFake;

beforeEach( function (): void {
    config( [
        'artisanpack.ecommerce-paypal.enabled'       => true,
        'artisanpack.ecommerce-paypal.client_id'     => 'test-client',
        'artisanpack.ecommerce-paypal.client_secret' => 'test-secret',
    ] );

    $this->paypal = PayPalFake::fake();
} );
```

The gateways register at boot, so set the config in your test environment (`phpunit.xml` env vars or your test case's environment setup) when you need them registered.

Every endpoint the package calls answers with a recorded fixture (shapes from PayPal's OpenAPI examples) until you queue something else. Requests to other hosts aren't intercepted; an unknown PayPal path gets a `404`.

## Queue responses

Queued responses are used once each, in order.

```php
use Illuminate\Http\Client\Request;

$this->paypal->respond( 'orders.capture', 'orders/captured' );                   // a fixture
$this->paypal->respond( 'orders.get', [ 'id' => 'X', 'status' => 'VOIDED' ] );   // a body
$this->paypal->respond( 'orders.get', 'orders/approved', 200, [ 'X-Extra' => '1' ] );
$this->paypal->error( 'orders.capture', 'instrument-declined' );                // a PayPal error (here a 422)
$this->paypal->error( 'payments.captures.refund', 'internal-server-error', times: 3 );
$this->paypal->timeout( 'payments.captures.refund' );                           // no response
```

| Method | Does |
| --- | --- |
| `respond( $endpoint, $body, ?$status, $headers )` | Queue a fixture name or a body array |
| `error( $endpoint, $error, ?$status, $times = 1 )` | Queue an error fixture, with PayPal's error shape and debug id `fake-debug-id` |
| `timeout( $endpoint, $times = 1 )` | Queue a connection timeout |
| `PayPalFake::fixture( $name )` | Decode a fixture, to build a variation |

Remember that the client retries timeouts, `429`s, and `5xx`s `http.retries` times; set `artisanpack.ecommerce-paypal.http.retries` to `0` in tests that shouldn't retry.

### Endpoints

`oauth.token`, `orders.create`, `orders.get`, `orders.patch`, `orders.authorize`, `orders.capture`, `payments.authorizations.get`, `payments.authorizations.capture`, `payments.authorizations.void`, `payments.authorizations.reauthorize`, `payments.captures.get`, `payments.captures.refund`, `payments.refunds.get`, `disputes.get`, `vault.payment_tokens.list`, `vault.payment_tokens.get`, `vault.payment_tokens.delete`, `vault.setup_tokens.create`, `vault.setup_tokens.get`, `webhooks.create`, `webhooks.list`, `webhooks.get`, `webhooks.update`, `webhooks.verify_signature`, `webhooks.events`.

### Fixtures

Under `src/Testing/fixtures/`: `orders/{created,approved,authorized,captured}`, `payments/{authorization,authorization-reauthorized,authorization-voided,capture,capture-pending,refund}`, `vault/{payment-token,payment-tokens,setup-token}`, `webhooks/{webhook,list,events,verify-signature,verify-signature-failure}`, `disputes/dispute`, `oauth/{token,id-token}`, and errors: `authorization-already-captured`, `capture-fully-refunded`, `instrument-declined`, `internal-server-error`, `invalid-client`, `invalid-request`, `order-already-captured`, `rate-limit`, `refund-amount-exceeded`, `resource-not-found`, `unauthorized`, `webhook-url-already-exists`.

## Assert requests

```php
$this->paypal->assertSent( 'orders.capture', fn ( Request $request ): bool => str_starts_with( $request->header( 'PayPal-Request-Id' )[0], 'capture-' ) );
$this->paypal->assertSentCount( 'payments.captures.refund', 1 );
$this->paypal->assertNotSent( 'payments.authorizations.void' );

$requests = $this->paypal->sent( 'orders.create' ); // every matching Request
```

## Webhooks

Webhook deliveries are verified against PayPal's certificate, so posting a raw event to `ecommerce/webhooks/paypal` is refused. In tests, either switch to `postback` verification, where the fake's `webhooks.verify_signature` answers `SUCCESS` by default (queue `$this->paypal->respond( 'webhooks.verify_signature', 'webhooks/verify-signature-failure' )` for a `FAILURE`):

```php
config( [
    'artisanpack.ecommerce-paypal.webhook_verification' => 'postback',
    'artisanpack.ecommerce-paypal.webhook_id'           => 'WH-TEST',
] );
```

and send the `PAYPAL-TRANSMISSION-ID`, `-TRANSMISSION-TIME` (now), `-TRANSMISSION-SIG`, `PAYPAL-CERT-URL` (on `api.sandbox.paypal.com` in sandbox mode), and `PAYPAL-AUTH-ALGO: SHA256withRSA` headers, or listen to `ap.ecommerce.paypal.webhookEvent` and call your handler directly.

## Front end

`resetSdk()` from the [JavaScript module](Extending-Javascript-Module) clears its SDK cache between JS tests.
