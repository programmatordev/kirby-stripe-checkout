<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/** @internal Developer-owned cleanup cadence, batch limits and lifecycle replay window. */
final readonly class HousekeepingConfiguration
{
    public function __construct(
        private int $intervalHours,
        private int $batchSize,
        private int $lifecycleDeliveryRetentionDays,
    ) {}

    public function intervalHours(): int
    {
        return $this->intervalHours;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function lifecycleDeliveryRetentionDays(): int
    {
        return $this->lifecycleDeliveryRetentionDays;
    }
}
