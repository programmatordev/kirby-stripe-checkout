<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;

/**
 * Exposes the safe localized selection data needed by a storefront.
 */
final readonly class ProductSelectionView
{
    /** @var list<ProductSelectionGroup> */
    private array $groups;

    /** @var list<ProductSelectionVariant> */
    private array $variants;

    /**
     * @param array<mixed> $groups
     * @param array<mixed> $variants
     */
    public function __construct(array $groups, array $variants)
    {
        if (
            array_is_list($groups) === false
            || array_is_list($variants) === false
            || ($groups === []) !== ($variants === [])
        ) {
            throw new InvalidProductException('product.selection_view_invalid');
        }

        $groupValues = [];

        foreach ($groups as $group) {
            if ($group instanceof ProductSelectionGroup === false || isset($groupValues[$group->id()])) {
                throw new InvalidProductException('product.selection_view_invalid');
            }

            $groupValues[$group->id()] = array_fill_keys(
                array_map(
                    static fn(ProductSelectionValue $value): string => $value->id(),
                    $group->values(),
                ),
                true,
            );
        }

        $variantIds = [];
        $groupIds = array_keys($groupValues);
        sort($groupIds);

        foreach ($variants as $variant) {
            if ($variant instanceof ProductSelectionVariant === false || isset($variantIds[$variant->id()])) {
                throw new InvalidProductException('product.selection_view_invalid');
            }

            $choices = $variant->choices();

            if (array_keys($choices) !== $groupIds) {
                throw new InvalidProductException('product.selection_view_invalid');
            }

            foreach ($choices as $groupId => $valueId) {
                if (isset($groupValues[$groupId][$valueId]) === false) {
                    throw new InvalidProductException('product.selection_view_invalid');
                }
            }

            $variantIds[$variant->id()] = true;
        }

        /** @var list<ProductSelectionGroup> $groups */
        $this->groups = $groups;
        /** @var list<ProductSelectionVariant> $variants */
        $this->variants = $variants;
    }

    /** @return list<ProductSelectionGroup> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<ProductSelectionVariant> */
    public function variants(): array
    {
        return $this->variants;
    }

    /** @param array<string, string> $choices */
    public function match(array $choices): ?ProductSelectionVariant
    {
        ksort($choices);

        foreach ($this->variants as $variant) {
            if ($variant->enabled() && $variant->choices() === $choices) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>,
     *   variants: list<array{id: string, choices: array<string, string>, enabled: bool}>
     * }
     */
    public function toArray(): array
    {
        return [
            'groups' => array_map(
                static fn(ProductSelectionGroup $group): array => $group->toArray(),
                $this->groups,
            ),
            'variants' => array_map(
                static fn(ProductSelectionVariant $variant): array => $variant->toArray(),
                $this->variants,
            ),
        ];
    }
}
