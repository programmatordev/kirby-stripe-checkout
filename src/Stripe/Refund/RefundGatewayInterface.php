<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Refund;

/** @internal Read-only Refund and parent correlation boundary. */
interface RefundGatewayInterface
{
    /** @return array<string, mixed> Selected untrusted Refund facts. */
    public function retrieve(string $refundId): array;

    /** @return array<string, mixed> Selected untrusted PaymentIntent ownership facts. */
    public function retrievePaymentIntent(string $paymentIntentId): array;

    /** @return array<string, mixed> Selected untrusted Charge backlink facts. */
    public function retrieveCharge(string $chargeId): array;

    /** @return list<array<string, mixed>> Complete selected Refund facts; partial pagination must throw. */
    public function allForPaymentIntent(string $paymentIntentId): array;
}
