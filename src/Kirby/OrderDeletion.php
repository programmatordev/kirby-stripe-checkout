<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use ProgrammatorDev\StripeCheckout\Lifecycle\LifecycleEvent;

/** @internal Carries a committed deletion beyond its write lock for hook dispatch. */
final readonly class OrderDeletion
{
    public function __construct(
        private OrderPage $order,
        private LifecycleEvent $event,
    ) {}

    public function order(): OrderPage
    {
        return $this->order;
    }

    public function event(): LifecycleEvent
    {
        return $this->event;
    }
}
