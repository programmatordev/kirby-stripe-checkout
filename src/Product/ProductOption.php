<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes one localized product option and its available values. */
final readonly class ProductOption
{
    private string $id;
    private string $name;

    /** @var list<ProductOptionValue> */
    private array $values;

    /** @param array<mixed> $values */
    public function __construct(string $id, string $name, array $values)
    {
        $this->id = ProductData::identifier($id);
        $this->name = ProductData::label($name);

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

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<ProductOptionValue> */
    public function values(): array
    {
        return $this->values;
    }

    /** @return array{id: string, name: string, values: list<array{id: string, name: string}>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'values' => array_map(
                static fn(ProductOptionValue $value): array => $value->toArray(),
                $this->values,
            ),
        ];
    }
}
