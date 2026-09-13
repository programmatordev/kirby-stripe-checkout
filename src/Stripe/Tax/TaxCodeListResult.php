<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

/** @internal Represents one provider page without leaking SDK collections. */
final readonly class TaxCodeListResult
{
    /** @param list<TaxCodeRecord> $taxCodes */
    public function __construct(
        private array $taxCodes,
        private bool $hasMore,
    ) {}

    /** @return list<TaxCodeRecord> */
    public function taxCodes(): array
    {
        return $this->taxCodes;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
