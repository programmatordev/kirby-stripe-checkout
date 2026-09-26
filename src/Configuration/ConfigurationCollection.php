<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Keeps canonical stored definitions with domain items built from the same
 * validation pass, so persistence and runtime configuration cannot diverge.
 *
 * @template T
 * @internal
 */
final readonly class ConfigurationCollection
{
    /**
     * @param list<array<string, mixed>> $definitions
     * @param list<T> $items
     */
    public function __construct(
        private array $definitions,
        private array $items,
    ) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return list<T> */
    public function items(): array
    {
        return $this->items;
    }
}
