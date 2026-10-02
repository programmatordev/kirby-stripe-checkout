<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Product\SelectedOption;

/**
 * Owns canonical editable options and their complete generated variant matrix.
 *
 * @internal
 */
final readonly class ProductOptionsDefinition
{
    /**
     * @param list<OptionDefinition> $options
     * @param list<VariantDefinition> $variants
     */
    public function __construct(
        private array $options,
        private array $variants,
    ) {}

    /** @return list<OptionDefinition> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return list<VariantDefinition> */
    public function variants(): array
    {
        return $this->variants;
    }

    /** @param list<OptionDefinition> $options */
    public function withLocalizedOptions(array $options): self
    {
        // Translations replace labels only; the canonical variant matrix owns availability, pricing and all other technical values.
        return new self($options, $this->variants);
    }

    /** @param array<string, string> $selectedOptions */
    public function variantFor(array $selectedOptions): ?VariantDefinition
    {
        ksort($selectedOptions);

        // Disabled variants still match here so the caller can distinguish an unavailable combination from malformed or incomplete selections.
        foreach ($this->variants as $variant) {
            $variantOptions = $variant->selectedOptions();
            ksort($variantOptions);

            if ($variantOptions === $selectedOptions) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $selectedOptions
     * @return list<SelectedOption>|null
     */
    public function selectedOptions(array $selectedOptions): ?array
    {
        $selected = [];

        foreach ($this->options as $option) {
            $valueId = $selectedOptions[$option->id()] ?? null;
            $value = is_string($valueId) ? $option->value($valueId) : null;

            if ($value === null) {
                return null;
            }

            $selected[] = new SelectedOption(
                $option->id(),
                $option->label(),
                $value->id(),
                $value->label(),
            );
        }

        return count($selectedOptions) === count($selected) ? $selected : null;
    }

    /**
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string, taxCode: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'options' => array_map(
                static fn(OptionDefinition $option): array => $option->toArray(),
                $this->options,
            ),
            'variants' => array_map(
                static fn(VariantDefinition $variant): array => $variant->toArray(),
                $this->variants,
            ),
        ];
    }
}
