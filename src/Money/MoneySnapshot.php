<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Money;

/**
 * Carries one currency and Stripe's exact signed provider-unit integer.
 *
 * @internal
 */
final readonly class MoneySnapshot
{
    public function __construct(
        private string $currency,
        private int $minorAmount,
    ) {}

    public function currency(): string
    {
        return $this->currency;
    }

    public function minorAmount(): int
    {
        return $this->minorAmount;
    }
}
