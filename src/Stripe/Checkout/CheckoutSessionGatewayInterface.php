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

    /** Reads Session fields without requiring payment/rate expansions or a complete line-item collection. */
    public function retrieve(string $sessionId): CheckoutSessionRecord;

    /**
     * Reads all line-item pages and expands the PaymentIntent, PaymentMethod,
     * latest Charge, selected Shipping Rate and aggregate discount/tax breakdown.
     * Failures or incomplete reads must throw, never return a partial reconciliation record.
     */
    public function retrieveForReconciliation(string $sessionId): CheckoutSessionReconciliationRecord;
}
