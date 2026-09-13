<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

/** @internal Carries exposed Tax Settings facts, not addresses or registrations. */
final readonly class TaxSettingsRecord
{
    /** @param array<mixed> $missingFields */
    public function __construct(
        public string $status,
        public bool $liveMode,
        public ?string $defaultTaxCode,
        public ?string $defaultTaxBehavior,
        public array $missingFields = [],
    ) {}
}
