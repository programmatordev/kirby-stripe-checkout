<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceProviderInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceRecord;
use RuntimeException;

final class FakePriceProvider implements PriceProviderInterface
{
    /** @var list<?string> */
    public array $listCursors = [];

    /** @var list<string> */
    public array $listCurrencies = [];

    /** @var list<string> */
    public array $retrievedIds = [];

    public bool $failLists = false;

    /**
     * @param array<string, PriceListResult> $pages
     * @param array<string, PriceRecord> $prices
     */
    public function __construct(
        private readonly array $pages = [],
        private readonly array $prices = [],
    ) {}

    public function list(string $currency, ?string $startingAfter = null): PriceListResult
    {
        $this->listCurrencies[] = $currency;
        $this->listCursors[] = $startingAfter;

        if ($this->failLists) {
            throw new RuntimeException('Simulated Stripe failure.');
        }

        return $this->pages[$startingAfter ?? 'first'] ?? new PriceListResult([], false);
    }

    public function retrieve(string $priceId): PriceRecord
    {
        $this->retrievedIds[] = $priceId;

        return $this->prices[$priceId] ?? throw new RuntimeException('Unknown fake Price.');
    }
}
