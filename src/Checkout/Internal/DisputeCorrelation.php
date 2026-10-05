<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Order\Internal\DisputeSnapshot;

/** @internal Current parent ownership and the triggering dispute's correlated stable identities. */
final readonly class DisputeCorrelation
{
    public function __construct(public string $pageUuid, public DisputeSnapshot $dispute, public ReconciliationEvent $trigger) {}
}
