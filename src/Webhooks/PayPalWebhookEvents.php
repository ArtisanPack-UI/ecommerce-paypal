<?php

/**
 * The PayPal webhook events the package handles.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

namespace ArtisanPackUI\EcommercePayPal\Webhooks;

/**
 * The event list from spec §8.2. `ecommerce-paypal:webhooks:register`
 * subscribes the webhook to exactly these.
 *
 * @package    ArtisanPack_UI
 * @subpackage EcommercePayPal
 *
 * @since      1.0.0
 */
final class PayPalWebhookEvents
{
    /**
     * Every subscribed event.
     *
     * @since 1.0.0
     *
     * @var array<int, string>
     */
    public const SUBSCRIBED = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.DECLINED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'PAYMENT.AUTHORIZATION.CREATED',
        'PAYMENT.AUTHORIZATION.VOIDED',
        'CUSTOMER.DISPUTE.CREATED',
        'CUSTOMER.DISPUTE.UPDATED',
        'CUSTOMER.DISPUTE.RESOLVED',
        'VAULT.PAYMENT-TOKEN.CREATED',
        'VAULT.PAYMENT-TOKEN.DELETED',
    ];
}
