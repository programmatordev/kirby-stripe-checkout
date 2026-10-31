<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Toolkit\Pagination;

/** @internal One native Kirby pagination page of Order summaries. */
final readonly class OrderListResult
{
    /** @param list<OrderSummary> $summaries */
    public function __construct(
        private array $summaries,
        private Pagination $pagination,
    ) {}

    /** @return list<OrderSummary> */
    public function summaries(): array
    {
        return $this->summaries;
    }

    public function pagination(): Pagination
    {
        return $this->pagination;
    }
}
