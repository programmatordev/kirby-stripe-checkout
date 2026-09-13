<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Stripe;

use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeListResult;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxProviderInterface;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxSettingsRecord;
use RuntimeException;

/** Supplies deterministic pages and account facts without Stripe traffic. */
final class FakeTaxProvider implements TaxProviderInterface
{
    /** @var list<?string> */
    public array $listCursors = [];
    public int $settingsReads = 0;
    public bool $failLists = false;
    public bool $failSettings = false;

    /** @param array<string, TaxCodeListResult> $pages */
    public function __construct(
        public array $pages = [],
        public ?TaxSettingsRecord $settings = null,
    ) {}

    public function listTaxCodes(?string $startingAfter = null): TaxCodeListResult
    {
        $this->listCursors[] = $startingAfter;

        if ($this->failLists) {
            throw new RuntimeException('PRIVATE PROVIDER DETAIL');
        }

        return $this->pages[$startingAfter ?? 'first'] ?? new TaxCodeListResult([], false);
    }

    public function retrieveSettings(): TaxSettingsRecord
    {
        $this->settingsReads++;

        if ($this->failSettings || $this->settings === null) {
            throw new RuntimeException('PRIVATE PROVIDER DETAIL');
        }

        return $this->settings;
    }
}
