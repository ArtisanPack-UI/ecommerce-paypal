# Hooks and filters

`artisanpack-ui/ecommerce-paypal` exposes its extension points through
[`artisanpack-ui/hooks`](https://github.com/ArtisanPack-UI/hooks). Every hook
this package fires uses the `ap.` prefix with camelCase segments.

Register callbacks in a service provider's `boot()` method. Filters must
return a value. Actions return nothing.

```php
addFilter( 'ap.ecommerce.paypal.orderPayload', function ( array $payload, Cart $cart, PayPalGateway $gateway ): array {
    // …
    return $payload;
}, 10, 3 );
```

Pass the accepted-argument count (the fourth argument) when your callback
takes more than one parameter.

## Hooks this package fires

| Hook | Type | Fired when |
|---|---|---|
| [`ap.ecommerce.paypal.orderPayload`](#apecommercepaypalorderpayload) | filter | A PayPal Order body is built |
| [`ap.ecommerce.paypal.clientOptions`](#apecommercepaypalclientoptions) | filter | The checkout's client config is built |
| [`ap.ecommerce.paypal.supportedCurrencies`](#apecommercepaypalsupportedcurrencies) | filter | A method's currency list is read |
| [`ap.ecommerce.paypal.captured`](#apecommercepaypalcaptured) | action | A capture takes money, once per order |
| [`ap.ecommerce.paypal.captureDenied`](#apecommercepaypalcapturedenied) | action | A pending capture on a placed order is denied |
| [`ap.ecommerce.paypal.authorizationExpiring`](#apecommercepaypalauthorizationexpiring) | action | An uncaptured authorization is 2 days from expiring |
| [`ap.ecommerce.paypal.disputeCreated`](#apecommercepaypaldisputecreated--disputeupdated--disputeresolved) | action | A buyer opens a dispute |
| [`ap.ecommerce.paypal.disputeUpdated`](#apecommercepaypaldisputecreated--disputeupdated--disputeresolved) | action | A dispute changes |
| [`ap.ecommerce.paypal.disputeResolved`](#apecommercepaypaldisputecreated--disputeupdated--disputeresolved) | action | A dispute is closed |
| [`ap.ecommerce.paypal.webhookEvent`](#apecommercepaypalwebhookevent) | action | A verified webhook arrives |

### `ap.ecommerce.paypal.orderPayload`

Filters the `POST /v2/checkout/orders` body before it is sent (spec §7.1).
Return the full payload.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$payload` | `array` | The Order body: `intent`, `purchase_units`, and `payment_source` |
| 2 | `$cart` | `ArtisanPackUI\Ecommerce\Models\Cart` | The cart being paid for |
| 3 | `$gateway` | `ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway` | The gateway creating the Order |

Returns `array`. A non-array return is ignored.

```php
// Add a reference to every PayPal Order.
addFilter( 'ap.ecommerce.paypal.orderPayload', function ( array $payload, Cart $cart ): array {
    $payload['purchase_units'][0]['description'] = 'Order from ' . config( 'app.name' );

    return $payload;
}, 10, 2 );
```

Changing `amount`, `items`, or `payment_source` can make PayPal reject the
Order. PayPal requires the breakdown to add up to `amount.value`.

### `ap.ecommerce.paypal.clientOptions`

Filters the `options` of the client config the storefront drivers and
`paypal-checkout.js` read (spec §9.1), for example button style or extra
SDK settings.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$options` | `array` | SDK and driver options: `order_id`, `amount`, `currency`, `intent`, `mode`, `locale`, `components`, `enable_funding`, `disable_funding`, `restart`, `vault`, `user_id_token`, plus driver options |
| 2 | `$cart` | `ArtisanPackUI\Ecommerce\Models\Cart` | The cart being paid for |
| 3 | `$session` | `ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession` | The payment session |
| 4 | `$gateway` | `ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway` | The gateway |

Returns `array`. The filter can't change `order_id`, `amount`, `currency`,
or `intent`: those are put back from the session. The driver, client id,
and Order id outside `options` aren't passed to it.

```php
addFilter( 'ap.ecommerce.paypal.clientOptions', function ( array $options ): array {
    $options['style'] = [ 'layout' => 'horizontal', 'color' => 'black' ];

    return $options;
} );
```

### `ap.ecommerce.paypal.supportedCurrencies`

Filters the currencies a payment method accepts (spec §6.2). A gateway is
hidden at checkout for carts in other currencies.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$currencies` | `array<int, string>` | Upper-case ISO 4217 codes |
| 2 | `$method` | `string` | `paypal`, `card`, `apple_pay`, `google_pay`, `pay_later`, or `venmo` |

Returns `array<int, string>`.

### `ap.ecommerce.paypal.captured`

Fires once per order when a capture takes money (`COMPLETED`) or starts
to (`PENDING`, settled later by webhook). A retried finalize that finds
the same capture doesn't fire it again.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$order` | `ArtisanPackUI\Ecommerce\Models\Order` | The engine order |
| 2 | `$captureId` | `string` | PayPal capture id (the payment's gateway reference) |
| 3 | `$status` | `string` | `COMPLETED` or `PENDING` |
| 4 | `$paypalOrder` | `ArtisanPackUI\EcommercePayPal\Api\Data\PayPalOrder` | The PayPal Order as captured |
| 5 | `$gateway` | `ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway` | The gateway that captured it |

```php
addAction( 'ap.ecommerce.paypal.captured', function ( Order $order, string $captureId, string $status ): void {
    Log::info( "PayPal capture {$captureId} ({$status}) for order {$order->order_number}" );
}, 10, 3 );
```

### `ap.ecommerce.paypal.captureDenied`

Fires when PayPal denies a capture that was pending on a placed order
(`PAYMENT.CAPTURE.DENIED`/`DECLINED`), after the order note and timeline
entry are written.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$order` | `ArtisanPackUI\Ecommerce\Models\Order` | The engine order |
| 2 | `$captureId` | `string` | PayPal capture id |
| 3 | `$status` | `string` | The capture's status at PayPal (`DENIED`, `DECLINED`) |

### `ap.ecommerce.paypal.authorizationExpiring`

Fires once per authorization, from the daily
`ecommerce-paypal:authorizations:reauthorize` run, when an uncaptured
authorization is within 2 days of expiring (spec §7.4).

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$order` | `ArtisanPackUI\Ecommerce\Models\Order` | The engine order |
| 2 | `$expiresAt` | `Carbon\CarbonImmutable` | When PayPal expires the authorization |

### `ap.ecommerce.paypal.disputeCreated` / `disputeUpdated` / `disputeResolved`

Fire for `CUSTOMER.DISPUTE.CREATED`, `.UPDATED`, and `.RESOLVED`, after the
order note and timeline entry are written (spec §8.3). Disputes never
change the order status, so these hooks are where store policy goes.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$order` | `ArtisanPackUI\Ecommerce\Models\Order` | The order paid with the disputed capture |
| 2 | `$dispute` | `array` | Summary: `id`, `reason`, `status`, `stage`, `outcome`, `amount`, `currency`, `respond_by` (ISO 8601), `capture_ids`, `url` (Resolution Center), `updated_at` |

```php
addAction( 'ap.ecommerce.paypal.disputeCreated', function ( Order $order, array $dispute ): void {
    Notification::route( 'mail', 'support@example.com' )->notify( new DisputeOpened( $order, $dispute ) );
}, 10, 2 );
```

### `ap.ecommerce.paypal.webhookEvent`

Fires for every verified PayPal webhook delivery the engine hasn't seen
before, whether or not this package acts on its type. It runs in the
webhook request, so queue anything slow.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$eventType` | `string` | The event type, e.g. `PAYMENT.CAPTURE.COMPLETED` |
| 2 | `$event` | `array` | The event as PayPal sent it (`id`, `event_type`, `resource`, …) |
| 3 | `$result` | `ArtisanPackUI\Ecommerce\ValueObjects\WebhookResult` | The engine's verified result |

The event body may hold payer details, so don't log or store it unredacted.
PayPal signs a CRC of the body, not the body itself: fetch a resource from
PayPal before acting on it, as this package's handlers do.

## Engine hooks this package relies on

### `ap.ecommerce.payment.availableGateways`

The engine filters the gateways offered for a cart. This package uses it
to hide a PayPal method that is switched off, or that doesn't accept the
cart's currency or country (spec §6.2). Hosts can use it too.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$gateways` | `array<string, PaymentGateway>` | Gateways keyed by gateway key |
| 2 | `$cart` | `ArtisanPackUI\Ecommerce\Models\Cart` | The cart being checked out |

The engine only accepts narrowing or reordering: entries that aren't the
registered gateway for their key are dropped.

```php
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;

// Hide every PayPal method for orders over $10,000.
addFilter( 'ap.ecommerce.payment.availableGateways', function ( array $gateways, Cart $cart ): array {
    if ( (int) $cart->total_amount <= 1_000_000 ) {
        return $gateways;
    }

    return array_filter( $gateways, fn ( $gateway ): bool => ! $gateway instanceof PayPalGateway );
}, 20, 2 );
```

Use a priority above 10 so your filter runs after this package's own.

### `ap.ecommerce.payment.clientConfig`

The engine filters the client config of every gateway after the gateway
builds it, so it sees the whole config (`driver`, `flow`,
`publishable_key`, `client_secret`, `redirect_url`, `options`). Prefer
`ap.ecommerce.paypal.clientOptions` for PayPal options: it keeps the
Order and amount from being changed.

| # | Parameter | Type | Description |
|---|---|---|---|
| 1 | `$config` | `array\|null` | The client config |
| 2 | `$gateway` | `PaymentGateway` | The gateway |
| 3 | `$cart` | `ArtisanPackUI\Ecommerce\Models\Cart` | The cart |
| 4 | `$session` | `ArtisanPackUI\Ecommerce\ValueObjects\PaymentSession` | The session |

```php
use ArtisanPackUI\EcommercePayPal\Gateways\PayPalGateway;

addFilter( 'ap.ecommerce.payment.clientConfig', function ( ?array $config, $gateway ): ?array {
    if ( $gateway instanceof PayPalGateway && null !== $config ) {
        $config['options']['style'] = [ 'shape' => 'pill' ];
    }

    return $config;
}, 10, 2 );
```
