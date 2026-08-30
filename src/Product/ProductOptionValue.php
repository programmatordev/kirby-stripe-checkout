<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes one stable, localized value within a product option. */
final readonly class ProductOptionValue
{
    private string $id;
    private string $name;

    public function __construct(string $id, string $name)
    {
        $this->id = ProductData::identifier($id);
        $this->name = ProductData::label($name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array{id: string, name: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
