<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Carries only the untrusted product reference and customer selection input.
 */
final readonly class ProductSelection
{
    private string $reference;

    /** @var array<string, string> */
    private array $choices;

    /** @param array<mixed, mixed> $choices */
    public function __construct(
        string $reference,
        private int $quantity = 1,
        array $choices = [],
    ) {
        $this->reference = ProductData::reference($reference);

        if ($this->quantity < 1 || $this->quantity > 999999 || count($choices) > 32) {
            throw new InvalidProductException('product.selection_invalid');
        }

        $normalized = [];

        foreach ($choices as $groupId => $valueId) {
            if (is_string($groupId) === false) {
                throw new InvalidProductException('product.selection_invalid');
            }

            $normalized[ProductData::identifier($groupId)] = ProductData::identifier($valueId);
        }

        ksort($normalized);
        $this->choices = $normalized;
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
    public function choices(): array
    {
        return $this->choices;
    }
}
