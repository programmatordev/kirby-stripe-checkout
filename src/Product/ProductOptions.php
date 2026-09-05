<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;

/**
 * Exposes the localized options and generated variants needed by a storefront.
 */
final readonly class ProductOptions
{
    /** @var list<ProductOption> */
    private array $options;

    /** @var list<ProductVariant> */
    private array $variants;

    /**
     * @param array<mixed> $options
     * @param array<mixed> $variants
     */
    public function __construct(array $options, array $variants)
    {
        if (
            array_is_list($options) === false
            || array_is_list($variants) === false
            || ($options === []) !== ($variants === [])
        ) {
            throw new InvalidProductException('product.options_invalid');
        }

        $valuesByOption = [];
        // Disabled variants still occupy a valid combination, so every Cartesian
        // row must be present for deterministic matching and availability checks.
        $expectedVariantCount = $options === [] ? 0 : 1;

        foreach ($options as $option) {
            if ($option instanceof ProductOption === false || isset($valuesByOption[$option->id()])) {
                throw new InvalidProductException('product.options_invalid');
            }

            $valuesByOption[$option->id()] = array_fill_keys(
                array_map(
                    static fn(ProductOptionValue $value): string => $value->id(),
                    $option->values(),
                ),
                true,
            );
            $valueCount = count($valuesByOption[$option->id()]);

            if ($expectedVariantCount > intdiv(PHP_INT_MAX, $valueCount)) {
                throw new InvalidProductException('product.options_invalid');
            }

            $expectedVariantCount *= $valueCount;
        }

        $variantIds = [];
        $variantSelections = [];
        $optionIds = array_keys($valuesByOption);
        sort($optionIds);

        foreach ($variants as $variant) {
            if ($variant instanceof ProductVariant === false || isset($variantIds[$variant->id()])) {
                throw new InvalidProductException('product.options_invalid');
            }

            $selectedOptions = $variant->selectedOptions();

            if (array_keys($selectedOptions) !== $optionIds) {
                throw new InvalidProductException('product.options_invalid');
            }

            foreach ($selectedOptions as $optionId => $valueId) {
                if (isset($valuesByOption[$optionId][$valueId]) === false) {
                    throw new InvalidProductException('product.options_invalid');
                }
            }

            $selectionKey = serialize($selectedOptions);

            if (isset($variantSelections[$selectionKey])) {
                throw new InvalidProductException('product.options_invalid');
            }

            $variantIds[$variant->id()] = true;
            $variantSelections[$selectionKey] = true;
        }

        if (count($variants) !== $expectedVariantCount) {
            throw new InvalidProductException('product.options_invalid');
        }

        /** @var list<ProductOption> $options */
        $this->options = $options;
        /** @var list<ProductVariant> $variants */
        $this->variants = $variants;
    }

    /** @return list<ProductOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return list<ProductVariant> */
    public function variants(): array
    {
        return $this->variants;
    }

    /** @param array<string, string> $selectedOptions */
    public function matchVariant(array $selectedOptions): ?ProductVariant
    {
        ksort($selectedOptions);

        foreach ($this->variants as $variant) {
            if ($variant->enabled() && $variant->selectedOptions() === $selectedOptions) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   options: list<array{id: string, name: string, values: list<array{id: string, name: string}>}>,
     *   variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?array{amount: string, currency: string}, stripePrice: ?array{priceId: string, productId: string, name: string, price: array{amount: string, currency: string}, taxBehavior: string, description: ?string, images: list<string>, nickname: ?string, taxCode: ?string}, requiresShipping: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'options' => array_map(
                static fn(ProductOption $option): array => $option->toArray(),
                $this->options,
            ),
            'variants' => array_map(
                static fn(ProductVariant $variant): array => $variant->toArray(),
                $this->variants,
            ),
        ];
    }
}
