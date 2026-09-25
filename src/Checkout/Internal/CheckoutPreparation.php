<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/**
 * Groups initiating order facts and optional accepted shipping evidence for one new attempt.
 * This is input to Session creation, not a persisted Order or a created Stripe Session.
 */
final readonly class CheckoutPreparation
{
    public function __construct(
        private OrderCreationContext $order,
        private ?InitiatingShippingSnapshot $shipping = null,
    ) {}

    public function order(): OrderCreationContext
    {
        return $this->order;
    }

    public function shipping(): ?InitiatingShippingSnapshot
    {
        return $this->shipping;
    }
}
