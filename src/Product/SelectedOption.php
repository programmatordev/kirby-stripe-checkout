<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Captures one selected value with its customer-language names. */
final readonly class SelectedOption
{
    private string $optionId;
    private string $optionName;
    private string $valueId;
    private string $valueName;

    public function __construct(
        string $optionId,
        string $optionName,
        string $valueId,
        string $valueName,
    ) {
        $this->optionId = ProductData::identifier($optionId);
        $this->optionName = ProductData::label($optionName);
        $this->valueId = ProductData::identifier($valueId);
        $this->valueName = ProductData::label($valueName);
    }

    public function optionId(): string
    {
        return $this->optionId;
    }

    public function optionName(): string
    {
        return $this->optionName;
    }

    public function valueId(): string
    {
        return $this->valueId;
    }

    public function valueName(): string
    {
        return $this->valueName;
    }
}
