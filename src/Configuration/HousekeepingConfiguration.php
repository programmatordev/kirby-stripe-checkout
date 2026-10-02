<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/** @internal Developer-owned cleanup cadence, batch limits and lifecycle payload retention. */
final readonly class HousekeepingConfiguration
{
    public function __construct(
        private int $intervalHours,
        private int $batchSize,
        private int $lifecycleDeliveryPayloadRetentionDays,
    ) {}

    public function intervalHours(): int
    {
        return $this->intervalHours;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function lifecycleDeliveryPayloadRetentionDays(): int
    {
        return $this->lifecycleDeliveryPayloadRetentionDays;
    }
}
