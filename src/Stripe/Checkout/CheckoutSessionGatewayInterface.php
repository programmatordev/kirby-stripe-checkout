<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

/** @internal Focused Stripe Checkout Session creation and retrieval boundary. */
interface CheckoutSessionGatewayInterface
{
    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord;

    public function retrieve(string $sessionId): CheckoutSessionRecord;
}
