<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

/** @internal Focused Stripe Checkout Session mutation boundary. */
interface CheckoutSessionGatewayInterface
{
    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord;
}
