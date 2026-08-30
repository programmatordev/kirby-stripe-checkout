<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Captures one selected value with its customer-language labels. */
final readonly class SelectedChoice
{
    private string $groupId;
    private string $groupLabel;
    private string $valueId;
    private string $valueLabel;

    public function __construct(
        string $groupId,
        string $groupLabel,
        string $valueId,
        string $valueLabel,
    ) {
        $this->groupId = ProductData::identifier($groupId);
        $this->groupLabel = ProductData::label($groupLabel);
        $this->valueId = ProductData::identifier($valueId);
        $this->valueLabel = ProductData::label($valueLabel);
    }

    public function groupId(): string
    {
        return $this->groupId;
    }

    public function groupLabel(): string
    {
        return $this->groupLabel;
    }

    public function valueId(): string
    {
        return $this->valueId;
    }

    public function valueLabel(): string
    {
        return $this->valueLabel;
    }
}
