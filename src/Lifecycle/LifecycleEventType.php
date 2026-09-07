<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle;

enum LifecycleEventType: string
{
    case OrderCreated = 'order.created';
    case SessionCreated = 'session.created';
    case PaymentPending = 'payment.pending';
    case PaymentSucceeded = 'payment.succeeded';
    case PaymentFailed = 'payment.failed';
    case CheckoutExpired = 'checkout.expired';
    case RefundUpdated = 'refund.updated';
    case DisputeUpdated = 'dispute.updated';
    case OrderDeleted = 'order.deleted';
}
