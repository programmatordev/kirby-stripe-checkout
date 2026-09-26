<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Provides named access to the validated Kirby product-field mapping.
 *
 * @internal
 */
final readonly class ProductFields
{
    /** @param list<string> $images */
    public function __construct(
        private string $name,
        private ?string $description,
        private array $images,
        private string $sku,
        private string $price,
        private string $stripePrice,
        private string $taxCode,
        private string $requiresShipping,
        private string $options,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function price(): string
    {
        return $this->price;
    }

    public function stripePrice(): string
    {
        return $this->stripePrice;
    }

    public function taxCode(): string
    {
        return $this->taxCode;
    }

    public function requiresShipping(): string
    {
        return $this->requiresShipping;
    }

    public function options(): string
    {
        return $this->options;
    }
}
