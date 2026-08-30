<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Carries an untrusted product reference and the customer's selected options.
 */
final readonly class ProductRequest
{
    private string $reference;

    /** @var array<string, string> */
    private array $selectedOptions;

    /** @param array<mixed, mixed> $selectedOptions */
    public function __construct(
        string $reference,
        private int $quantity = 1,
        array $selectedOptions = [],
    ) {
        $this->reference = ProductData::reference($reference);

        if ($this->quantity < 1 || $this->quantity > 999999 || count($selectedOptions) > 32) {
            throw new InvalidProductException('product.request_invalid');
        }

        $normalized = [];

        foreach ($selectedOptions as $optionId => $valueId) {
            if (is_string($optionId) === false) {
                throw new InvalidProductException('product.request_invalid');
            }

            $normalized[ProductData::identifier($optionId)] = ProductData::identifier($valueId);
        }

        ksort($normalized);
        $this->selectedOptions = $normalized;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    /** @return array<string, string> */
    public function selectedOptions(): array
    {
        return $this->selectedOptions;
    }
}
