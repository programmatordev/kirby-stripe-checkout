<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes the safe identity, selected options, and state of one generated variant. */
final readonly class ProductVariant
{
    private string $id;

    /** @var array<string, string> */
    private array $selectedOptions;

    /** @param array<mixed, mixed> $selectedOptions */
    public function __construct(string $id, array $selectedOptions, private bool $enabled)
    {
        $this->id = ProductData::identifier($id);
        $normalized = [];

        foreach ($selectedOptions as $optionId => $valueId) {
            if (is_string($optionId) === false) {
                throw new InvalidProductException('product.options_invalid');
            }

            $normalized[ProductData::identifier($optionId)] = ProductData::identifier($valueId);
        }

        if ($normalized === []) {
            throw new InvalidProductException('product.options_invalid');
        }

        ksort($normalized);
        $this->selectedOptions = $normalized;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public function selectedOptions(): array
    {
        return $this->selectedOptions;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return array{id: string, selectedOptions: array<string, string>, enabled: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'selectedOptions' => $this->selectedOptions,
            'enabled' => $this->enabled,
        ];
    }
}
