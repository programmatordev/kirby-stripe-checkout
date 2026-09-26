<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/** @internal Request-driven cleanup cadence and batch limits. */
final readonly class HousekeepingConfiguration
{
    public function __construct(
        private int $intervalHours,
        private int $batchSize,
    ) {}

    public function intervalHours(): int
    {
        return $this->intervalHours;
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }
}
