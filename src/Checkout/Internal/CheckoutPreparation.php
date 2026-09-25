<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Groups the purchase and optional accepted shipping quote for one new attempt. */
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
