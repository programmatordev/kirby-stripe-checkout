<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Captures one selected value with its customer-language labels. */
final readonly class SelectedOption
{
    private string $optionId;
    private string $optionLabel;
    private string $valueId;
    private string $valueLabel;

    public function __construct(
        string $optionId,
        string $optionLabel,
        string $valueId,
        string $valueLabel,
    ) {
        $this->optionId = ProductData::identifier($optionId);
        $this->optionLabel = ProductData::label($optionLabel);
        $this->valueId = ProductData::identifier($valueId);
        $this->valueLabel = ProductData::label($valueLabel);
    }

    public function optionId(): string
    {
        return $this->optionId;
    }

    public function optionLabel(): string
    {
        return $this->optionLabel;
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
