<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe;

/**
 * One clamped page of catalogue results with the state it came from.
 *
 * @template T
 * @template TState
 * @internal
 */
final readonly class CataloguePage
{
    /**
     * @param list<T> $items
     * @param CatalogueState<TState> $state
     */
    public function __construct(
        private array $items,
        private int $page,
        private int $pages,
        private int $total,
        private CatalogueState $state,
    ) {}

    /** @return list<T> */
    public function items(): array
    {
        return $this->items;
    }

    public function page(): int
    {
        return $this->page;
    }

    public function pages(): int
    {
        return $this->pages;
    }

    public function total(): int
    {
        return $this->total;
    }

    /** @return CatalogueState<TState> */
    public function state(): CatalogueState
    {
        return $this->state;
    }
}
