<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxProviderInterface;
use RuntimeException;

/** Supplies deterministic Tax Code pages without Stripe traffic. */
final class FakeTaxProvider implements TaxProviderInterface
{
    /** @var list<?string> */
    public array $listCursors = [];
    public bool $failLists = false;

    /** @param array<string, TaxCodeListResult> $pages */
    public function __construct(
        public array $pages = [],
    ) {}

    public function listTaxCodes(?string $startingAfter = null): TaxCodeListResult
    {
        $this->listCursors[] = $startingAfter;

        if ($this->failLists) {
            throw new RuntimeException('PRIVATE PROVIDER DETAIL');
        }

        return $this->pages[$startingAfter ?? 'first'] ?? new TaxCodeListResult([], false);
    }
}
