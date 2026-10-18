<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/** @internal Developer-owned housekeeping work limits and lifecycle payload retention. */
final readonly class HousekeepingConfiguration
{
    public function __construct(
        private int $batchSize,
        private int $lifecycleDeliveryPayloadRetentionDays,
    ) {}

    public function batchSize(): int
    {
        return $this->batchSize;
    }

    public function lifecycleDeliveryPayloadRetentionDays(): int
    {
        return $this->lifecycleDeliveryPayloadRetentionDays;
    }
}
