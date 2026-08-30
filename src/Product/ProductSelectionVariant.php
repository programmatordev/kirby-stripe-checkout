<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes the safe identity, choices, and state of one generated variant. */
final readonly class ProductSelectionVariant
{
    private string $id;

    /** @var array<string, string> */
    private array $choices;

    /** @param array<mixed, mixed> $choices */
    public function __construct(string $id, array $choices, private bool $enabled)
    {
        $this->id = ProductData::identifier($id);
        $normalized = [];

        foreach ($choices as $groupId => $valueId) {
            if (is_string($groupId) === false) {
                throw new InvalidProductException('product.selection_view_invalid');
            }

            $normalized[ProductData::identifier($groupId)] = ProductData::identifier($valueId);
        }

        if ($normalized === []) {
            throw new InvalidProductException('product.selection_view_invalid');
        }

        ksort($normalized);
        $this->choices = $normalized;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public function choices(): array
    {
        return $this->choices;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** @return array{id: string, choices: array<string, string>, enabled: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'choices' => $this->choices,
            'enabled' => $this->enabled,
        ];
    }
}
