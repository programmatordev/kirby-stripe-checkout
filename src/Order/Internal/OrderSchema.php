<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

/** @internal Single registry for content serialization and reserved field protection. */
final class OrderSchema
{
    public const OWNER = 'programmatordev/stripe-checkout';
    public const VERSION = 1;
    public const TEMPLATE = 'stripe-checkout-order';
    public const CONTAINER = 'stripe-checkout-orders';

    /** @var list<string> */
    public const FINAL_AMOUNTS = ['discountTotal', 'shippingTotal', 'taxTotal', 'total'];

    /** @var list<string> */
    public const AMOUNTS = ['subtotal', ...self::FINAL_AMOUNTS, 'refundedTotal'];

    /** @var list<string> */
    public const FLAGS = ['refundHasActive', 'refundRequiresAction', 'refundHasFailed', 'disputeRequiresResponse', 'disputeHasLost'];

    /** @var array<string, string> Field name to provider identifier prefix. */
    public const REFERENCES = [
        'stripeCheckoutSessionId' => 'cs_',
        'stripePaymentIntentId' => 'pi_',
        'stripeChargeId' => 'ch_',
        'stripeCustomerId' => 'cus_',
        'stripeInvoiceId' => 'in_',
        'stripeShippingRateId' => 'shr_',
    ];

    /** @var list<string> */
    public const TIMESTAMPS = ['createdAt', 'updatedAt', 'checkoutExpiresAt', 'checkoutOpenedAt', 'creationUncertainAt', 'creationFailedAt', 'checkoutCompletedAt', 'checkoutExpiredAt', 'paidAt', 'paymentFailedAt', 'refundUpdatedAt', 'disputeUpdatedAt', 'lastEventAt'];

    /** @var list<string> */
    public const SNAPSHOTS = ['stripeCheckout', 'checkoutAttempt', 'initiatingLineItems', 'lineItems', 'customer', 'billingAddress', 'shippingAddress', 'customFields', 'consent', 'discounts', 'tax', 'shipping', 'payment', 'refunds', 'disputes', 'events'];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            'title', 'uuid', 'orderNumber', 'userUuid', 'languageCode', 'currency',
            'checkoutStatus', 'paymentStatus', 'refundStatus', 'disputeStatus',
            ...self::AMOUNTS, ...self::FLAGS, ...array_keys(self::REFERENCES), ...self::TIMESTAMPS, ...self::SNAPSHOTS,
        ];
    }

    public static function isReserved(string $field): bool
    {
        return in_array(strtolower($field), array_map(strtolower(...), [...self::fields(), 'slug', 'template']), true);
    }
}
