<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Dispute;

/** @internal Read-only Dispute and parent correlation boundary. */
interface DisputeGatewayInterface
{
    /** @return array<string, mixed> Selected untrusted Dispute facts. */
    public function retrieve(string $disputeId): array;

    /** @return array<string, mixed> Selected untrusted PaymentIntent ownership facts. */
    public function retrievePaymentIntent(string $paymentIntentId): array;

    /** @return array<string, mixed> Selected untrusted Charge backlink facts. */
    public function retrieveCharge(string $chargeId): array;

    /** @return list<array<string, mixed>> Complete selected Dispute facts; partial pagination must throw. */
    public function allForPaymentIntent(string $paymentIntentId): array;
}
