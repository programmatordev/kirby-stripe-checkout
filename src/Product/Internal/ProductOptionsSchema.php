<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use InvalidArgumentException;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Normalizes canonical product-option definitions and translated label overlays.
 *
 * @internal
 */
final class ProductOptionsSchema
{
    public function canonical(mixed $value): ProductOptionsDefinition
    {
        $data = $this->decode($value);

        if (array_diff(array_keys($data), ['options', 'variants']) !== []) {
            throw new InvalidArgumentException('Product option data contains an unknown root property.');
        }

        $options = $this->options($data['options'] ?? []);
        $variants = $this->variants($data['variants'] ?? [], $options);

        if ($options === [] && $variants !== []) {
            throw new InvalidArgumentException('Variants require at least one option.');
        }

        return new ProductOptionsDefinition(
            options: $options,
            variants: (new VariantMatrix())->reconcile($options, $variants),
        );
    }

    /**
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>}
     */
    public function overlay(ProductOptionsDefinition $canonical, mixed $value): array
    {
        // Secondary languages may submit labels only. Rebuilding from canonical
        // IDs ignores unknown entries and prevents translated content from
        // changing the technical option or variant schema.
        $input = $this->decode($value);
        $submittedOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
        $submittedById = [];

        foreach ($submittedOptions as $submittedOption) {
            if (is_array($submittedOption) && is_string($submittedOption['id'] ?? null)) {
                $submittedById[$submittedOption['id']] = $submittedOption;
            }
        }

        $options = [];

        foreach ($canonical->options() as $option) {
            $submittedOption = $submittedById[$option->id()] ?? [];
            $submittedValues = is_array($submittedOption['values'] ?? null)
                ? $submittedOption['values']
                : [];
            $submittedValuesById = [];

            foreach ($submittedValues as $submittedValue) {
                if (is_array($submittedValue) && is_string($submittedValue['id'] ?? null)) {
                    $submittedValuesById[$submittedValue['id']] = $submittedValue;
                }
            }

            $values = [];

            foreach ($option->values() as $valueDefinition) {
                $submittedValue = $submittedValuesById[$valueDefinition->id()] ?? [];
                $values[] = [
                    'id' => $valueDefinition->id(),
                    'label' => $this->optionalLabel($submittedValue['label'] ?? null),
                ];
            }

            $options[] = [
                'id' => $option->id(),
                'label' => $this->optionalLabel($submittedOption['label'] ?? null),
                'values' => $values,
            ];
        }

        return ['options' => $options];
    }

    public function localized(ProductOptionsDefinition $canonical, mixed $overlay): ProductOptionsDefinition
    {
        $overlayData = $this->decode($overlay);
        $overlayOptions = is_array($overlayData['options'] ?? null) ? $overlayData['options'] : [];
        $optionsById = [];

        foreach ($overlayOptions as $option) {
            if (is_array($option) && is_string($option['id'] ?? null)) {
                $optionsById[$option['id']] = $option;
            }
        }

        $localizedOptions = [];

        foreach ($canonical->options() as $option) {
            $overlayOption = $optionsById[$option->id()] ?? [];
            $valuesById = [];

            foreach (is_array($overlayOption['values'] ?? null) ? $overlayOption['values'] : [] as $value) {
                if (is_array($value) && is_string($value['id'] ?? null)) {
                    $valuesById[$value['id']] = $value;
                }
            }

            $values = [];

            foreach ($option->values() as $value) {
                $label = $this->optionalLabel($valuesById[$value->id()]['label'] ?? null);
                $values[] = $value->withLabel($label === '' ? $value->label() : $label);
            }

            $label = $this->optionalLabel($overlayOption['label'] ?? null);
            $localizedOptions[] = $option->localized(
                label: $label === '' ? $option->label() : $label,
                values: $values,
            );
        }

        return $canonical->withLocalizedOptions($localizedOptions);
    }

    /** @return array<string, mixed> */
    public function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $this->stringKeyed($value);
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('Product option data must be an array or YAML string.');
        }

        return $this->stringKeyed(Yaml::decode($value));
    }

    /**
     * @return list<OptionDefinition>
     */
    private function options(mixed $options): array
    {
        if (is_array($options) === false || array_is_list($options) === false) {
            throw new InvalidArgumentException('Options must be a list.');
        }

        $normalized = [];
        $ids = [];

        foreach ($options as $option) {
            if (is_array($option) === false) {
                throw new InvalidArgumentException('Each option must be an object.');
            }

            $id = $this->requiredId($option['id'] ?? null, 'option');
            $this->assertUnique($ids, $id, 'option');
            $values = $option['values'] ?? null;

            if (is_array($values) === false || array_is_list($values) === false || $values === []) {
                throw new InvalidArgumentException('Each option must contain at least one value.');
            }

            $normalizedValues = [];
            $valueIds = [];

            foreach ($values as $value) {
                if (is_array($value) === false) {
                    throw new InvalidArgumentException('Each option value must be an object.');
                }

                $valueId = $this->requiredId($value['id'] ?? null, 'value');
                $this->assertUnique($valueIds, $valueId, 'value');
                $normalizedValues[] = new OptionValueDefinition(
                    id: $valueId,
                    label: $this->requiredLabel($value['label'] ?? null, 'value'),
                );
            }

            $normalized[] = new OptionDefinition(
                id: $id,
                label: $this->requiredLabel($option['label'] ?? null, 'option'),
                values: $normalizedValues,
            );
        }

        return $normalized;
    }

    /**
     * @param list<OptionDefinition> $options
     * @return list<VariantDefinition>
     */
    private function variants(mixed $variants, array $options): array
    {
        if (is_array($variants) === false || array_is_list($variants) === false) {
            throw new InvalidArgumentException('Variants must be a list.');
        }

        $knownValues = [];

        foreach ($options as $option) {
            $knownValues[$option->id()] = array_map(
                static fn(OptionValueDefinition $value): string => $value->id(),
                $option->values(),
            );
        }

        $normalized = [];
        $ids = [];
        $optionCombinationKeys = [];

        foreach ($variants as $variant) {
            if (is_array($variant) === false) {
                throw new InvalidArgumentException('Each variant must be an object.');
            }

            $id = $this->requiredId($variant['id'] ?? null, 'variant');
            $this->assertUnique($ids, $id, 'variant');
            $selectedOptions = $variant['selectedOptions'] ?? null;

            if (is_array($selectedOptions) === false) {
                throw new InvalidArgumentException('Each variant must define its selected options.');
            }

            $normalizedOptions = [];

            foreach ($knownValues as $optionId => $valueIds) {
                $valueId = $selectedOptions[$optionId] ?? null;

                if (is_string($valueId) === false || in_array($valueId, $valueIds, true) === false) {
                    throw new InvalidArgumentException('A variant references an unknown option value.');
                }

                $normalizedOptions[$optionId] = $valueId;
            }

            if (array_diff(array_keys($selectedOptions), array_keys($normalizedOptions)) !== []) {
                throw new InvalidArgumentException('A variant contains an unknown selected option.');
            }

            $optionCombinationKey = VariantMatrix::optionCombinationKey($normalizedOptions);
            $this->assertUnique($optionCombinationKeys, $optionCombinationKey, 'combination');
            $shipping = $variant['requiresShipping'] ?? 'inherit';
            $enabled = $variant['enabled'] ?? true;

            if (in_array($shipping, ['inherit', 'yes', 'no'], true) === false) {
                throw new InvalidArgumentException('A variant has an invalid shipping override.');
            }

            if (is_bool($enabled) === false) {
                throw new InvalidArgumentException('A variant has an invalid availability value.');
            }

            $normalized[] = new VariantDefinition(
                id: $id,
                selectedOptions: $normalizedOptions,
                enabled: $enabled,
                sku: $this->nullableString($variant['sku'] ?? null, 'sku'),
                price: $this->nullableString($variant['price'] ?? null, 'price'),
                stripePriceId: $this->nullableString(
                    $variant['stripePriceId'] ?? null,
                    'stripePriceId',
                ),
                shippingOverride: match ($shipping) {
                    'yes' => true,
                    'no' => false,
                    default => null,
                },
                taxCodeId: $this->nullableString($variant['taxCode'] ?? null, 'taxCode'),
            );
        }

        return $normalized;
    }

    private function requiredId(mixed $value, string $kind): string
    {
        if (
            is_string($value) === false
            || preg_match('/^[A-Za-z0-9_-]{4,64}$/', $value) !== 1
            // Option IDs become associative selection keys; PHP coerces an
            // all-digit key to int and would break the string-key contract.
            || $kind === 'option' && ctype_digit($value)
        ) {
            throw new InvalidArgumentException(sprintf('Each %s requires a stable ID.', $kind));
        }

        return $value;
    }

    private function requiredLabel(mixed $value, string $kind): string
    {
        if (
            is_string($value) === false
            || trim($value) === ''
            || trim($value) !== $value
            || strlen($value) > 500
            || TextValidator::isSingleLine($value) === false
        ) {
            throw new InvalidArgumentException(sprintf('Each %s requires a label.', $kind));
        }

        return $value;
    }

    private function optionalLabel(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('A translated name must be a string.');
        }

        if (TextValidator::isSingleLine($value) === false) {
            throw new InvalidArgumentException('A translated name must be safe single-line text.');
        }

        $value = trim($value);

        return $value === '' ? '' : $this->requiredLabel($value, 'translation');
    }

    private function nullableString(mixed $value, string $kind): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('Variant commerce values must be strings.');
        }

        if (
            trim($value) !== $value
            || strlen($value) > 500
            || TextValidator::isSingleLine($value) === false
        ) {
            throw new InvalidArgumentException('A variant has an invalid commerce value.');
        }

        if ($kind === 'price' && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new InvalidArgumentException('A variant has an invalid price.');
        }

        if (
            $kind === 'stripePriceId'
            && preg_match('/^price_[A-Za-z0-9]{1,249}$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('A variant has an invalid Stripe Price ID.');
        }

        return $value;
    }

    /**
     * @param array<mixed, mixed> $data
     * @return array<string, mixed>
     */
    private function stringKeyed(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (is_string($key) === false) {
                throw new InvalidArgumentException('Product option data requires named root properties.');
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, true> $seen */
    private function assertUnique(array &$seen, string $id, string $kind): void
    {
        if (isset($seen[$id])) {
            throw new InvalidArgumentException(sprintf('Duplicate %s ID: %s.', $kind, $id));
        }

        $seen[$id] = true;
    }
}
