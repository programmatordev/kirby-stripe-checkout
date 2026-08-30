<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes one localized product option and its available values. */
final readonly class ProductOption
{
    private string $id;
    private string $label;

    /** @var list<ProductOptionValue> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(string $id, string $label, array $values)
    {
        $this->id = ProductData::identifier($id);
        $this->label = ProductData::label($label);

        if ($values === [] || array_is_list($values) === false) {
            throw new InvalidProductException('product.options_invalid');
        }

        $ids = [];

        foreach ($values as $value) {
            if ($value instanceof ProductOptionValue === false || isset($ids[$value->id()])) {
                throw new InvalidProductException('product.options_invalid');
            }

            $ids[$value->id()] = true;
        }

        $this->values = $values;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<ProductOptionValue> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array{id: string, label: string, values: list<array{id: string, label: string}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'values' => array_map(
                static fn(ProductOptionValue $value): array => $value->toArray(),
                $this->values,
            ),
        ];
    }
}
