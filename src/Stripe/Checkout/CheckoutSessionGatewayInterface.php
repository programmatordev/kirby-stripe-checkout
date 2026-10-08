<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use ProgrammatorDev\StripeCheckout\Checkout\SessionRequest;

/** @internal Focused Stripe Checkout Session creation, discovery and retrieval boundary. */
interface CheckoutSessionGatewayInterface
{
    public function create(
        SessionRequest $request,
        string $idempotencyKey,
    ): CheckoutSessionRecord;

    /** Reads Session fields without requiring payment/rate expansions or a complete line-item collection. */
    public function retrieve(string $sessionId): CheckoutSessionRecord;

    /**
     * Reads all line-item pages and expands the PaymentIntent, PaymentMethod, latest Charge, selected Shipping Rate, shipping taxes and aggregate discount/tax breakdown.
     * Failures or incomplete reads must throw, never return a partial reconciliation record.
     */
    public function retrieveForReconciliation(string $sessionId): CheckoutSessionReconciliationRecord;

    /** Exact PaymentIntent lookup; no ID for no match, ambiguous/incomplete results throw. */
    public function sessionForPaymentIntent(string $paymentIntentId): ?string;

    /** Reads one creation-window page, selecting ownership-matched IDs without certifying purchase correlation. */
    public function discoverForOrder(
        string $pageUuid,
        int $createdFrom,
        int $createdBefore,
        ?string $startingAfter = null,
    ): CheckoutSessionDiscoveryPage;
}
