<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/** Exposes one variant with its effective commerce values. */
final readonly class ProductVariant
{
    private string $id;

    /** @var array<string, string> */
    private array $selectedOptions;

    private ?string $sku;

    /** @param array<mixed, mixed> $selectedOptions */
    public function __construct(
        string $id,
        array $selectedOptions,
        private bool $enabled,
        private InlinePrice|StripePriceReference $price,
        private bool $requiresShipping,
        ?string $sku = null,
    ) {
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
        $this->sku = ProductData::optionalString($sku, 500);
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

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function price(): InlinePrice|StripePriceReference
    {
        return $this->price;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    /**
     * @return array{
     *   id: string,
     *   selectedOptions: array<string, string>,
     *   enabled: bool,
     *   sku: ?string,
     *   price: array{source: 'kirby', amount: string, currency: string}|array{source: 'stripe', priceId: string},
     *   requiresShipping: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'selectedOptions' => $this->selectedOptions,
            'enabled' => $this->enabled,
            'sku' => $this->sku,
            'price' => $this->price instanceof InlinePrice
                ? [
                    'source' => 'kirby',
                    'amount' => $this->price->unitPrice()->getAmount()->toString(),
                    'currency' => $this->price->unitPrice()->getCurrency()->getCurrencyCode(),
                ]
                : [
                    'source' => 'stripe',
                    'priceId' => $this->price->priceId(),
                ],
            'requiresShipping' => $this->requiresShipping,
        ];
    }
}
