<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

/** @internal One deterministic bounded slice of the native Order inventory. */
final readonly class OrderReferenceBatch
{
    /** @param list<string> $pageUuids */
    public function __construct(
        private array $pageUuids,
        private ?string $continueAfterPageUuid,
    ) {}

    /** @return list<string> */
    public function pageUuids(): array
    {
        return $this->pageUuids;
    }

    public function continueAfterPageUuid(): ?string
    {
        return $this->continueAfterPageUuid;
    }
}
