<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Constructs the final Checkout Session request from the accumulated request. */
interface SessionRequestFactoryInterface
{
    public function create(
        SessionRequestContext $context,
        SessionRequest $request,
    ): SessionRequest;
}
