<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes one stable, localized value within a product option. */
final readonly class ProductOptionValue
{
    private string $id;
    private string $label;

    public function __construct(string $id, string $label)
    {
        $this->id = ProductData::identifier($id);
        $this->label = ProductData::label($label);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return array{id: string, label: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}
