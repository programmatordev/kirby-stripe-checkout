<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

/** @internal Authoritative applied discounts and aggregate discount total for one Checkout Session. */
final readonly class CheckoutDiscountsSnapshot
{
    /** @param list<DiscountSnapshot> $discounts */
    private function __construct(
        private array $discounts,
        private ?string $total,
    ) {}

    public static function unavailable(): self
    {
        return new self([], null);
    }

    /** @param list<DiscountSnapshot> $discounts */
    public static function available(array $discounts, string $total): self
    {
        return new self($discounts, $total);
    }

    /** @return list<DiscountSnapshot> */
    public function discounts(): array
    {
        return $this->discounts;
    }

    public function total(): ?string
    {
        return $this->total;
    }
}
