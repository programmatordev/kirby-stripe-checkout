<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Constructs a complete replacement for the standard Checkout Session request. */
interface SessionRequestFactoryInterface
{
    public function create(
        SessionRequestContext $context,
        SessionRequest $standard,
    ): SessionRequest;
}
