<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

/**
 * Represents one provider list result without leaking Stripe response collections.
 *
 * @internal
 */
final readonly class PriceListResult
{
    /** @param list<PriceRecord> $prices */
    public function __construct(
        private array $prices,
        private bool $hasMore,
    ) {}

    /** @return list<PriceRecord> */
    public function prices(): array
    {
        return $this->prices;
    }

    public function hasMore(): bool
    {
        return $this->hasMore;
    }
}
