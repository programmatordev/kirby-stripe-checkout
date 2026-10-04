<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Order\Internal\RefundSnapshot;

/** @internal Current parent ownership and the triggering refund's correlated stable identities. */
final readonly class RefundCorrelation
{
    public function __construct(public string $pageUuid, public RefundSnapshot $refund, public ReconciliationEvent $trigger) {}
}
