<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use InvalidArgumentException;
use Kirby\Data\Yaml;

/**
 * Normalizes canonical variant data and translated label overlays.
 *
 * @internal
 */
final class VariantSchema
{
    /**
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    public function canonical(mixed $value): array
    {
        $data = $this->decode($value);

        if (array_diff(array_keys($data), ['options', 'variants']) !== []) {
            throw new InvalidArgumentException('Variant data contains an unknown root property.');
        }

        $options = $this->options($data['options'] ?? []);
        $variants = $this->variants($data['variants'] ?? [], $options);

        if ($options === [] && $variants !== []) {
            throw new InvalidArgumentException('Variants require at least one option.');
        }

        return [
            'options' => $options,
            'variants' => (new VariantMatrix())->reconcile($options, $variants),
        ];
    }

    /**
     * @param array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>}
     */
    public function overlay(array $canonical, mixed $value): array
    {
        $input = $this->decode($value);
        $submittedOptions = is_array($input['options'] ?? null) ? $input['options'] : [];
        $submittedById = [];

        foreach ($submittedOptions as $submittedOption) {
            if (is_array($submittedOption) && is_string($submittedOption['id'] ?? null)) {
                $submittedById[$submittedOption['id']] = $submittedOption;
            }
        }

        $options = [];

        foreach ($canonical['options'] as $option) {
            $submittedOption = $submittedById[$option['id']] ?? [];
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

            foreach ($option['values'] as $valueDefinition) {
                $submittedValue = $submittedValuesById[$valueDefinition['id']] ?? [];
                $values[] = [
                    'id' => $valueDefinition['id'],
                    'label' => $this->optionalLabel($submittedValue['label'] ?? null),
                ];
            }

            $options[] = [
                'id' => $option['id'],
                'label' => $this->optionalLabel($submittedOption['label'] ?? null),
                'values' => $values,
            ];
        }

        return ['options' => $options];
    }

    /**
     * @param array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    public function localized(array $canonical, mixed $overlay): array
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

        foreach ($canonical['options'] as $option) {
            $overlayOption = $optionsById[$option['id']] ?? [];
            $valuesById = [];

            foreach (is_array($overlayOption['values'] ?? null) ? $overlayOption['values'] : [] as $value) {
                if (is_array($value) && is_string($value['id'] ?? null)) {
                    $valuesById[$value['id']] = $value;
                }
            }

            $values = [];

            foreach ($option['values'] as $value) {
                $label = $this->optionalLabel($valuesById[$value['id']]['label'] ?? null);
                $values[] = [...$value, 'label' => $label === '' ? $value['label'] : $label];
            }

            $label = $this->optionalLabel($overlayOption['label'] ?? null);
            $localizedOptions[] = [
                ...$option,
                'label' => $label === '' ? $option['label'] : $label,
                'values' => $values,
            ];
        }

        return [...$canonical, 'options' => $localizedOptions];
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
            throw new InvalidArgumentException('Variant data must be an array or YAML string.');
        }

        return $this->stringKeyed(Yaml::decode($value));
    }

    /**
     * @return list<array{id: string, label: string, values: list<array{id: string, label: string}>}>
     */
    private function options(mixed $options): array
    {
        if (is_array($options) === false || array_is_list($options) === false) {
            throw new InvalidArgumentException('Variant options must be a list.');
        }

        $normalized = [];
        $ids = [];

        foreach ($options as $option) {
            if (is_array($option) === false) {
                throw new InvalidArgumentException('Each variant option must be an object.');
            }

            $id = $this->requiredId($option['id'] ?? null, 'option');
            $this->assertUnique($ids, $id, 'option');
            $values = $option['values'] ?? null;

            if (is_array($values) === false || array_is_list($values) === false || $values === []) {
                throw new InvalidArgumentException('Each variant option must contain at least one value.');
            }

            $normalizedValues = [];
            $valueIds = [];

            foreach ($values as $value) {
                if (is_array($value) === false) {
                    throw new InvalidArgumentException('Each variant value must be an object.');
                }

                $valueId = $this->requiredId($value['id'] ?? null, 'value');
                $this->assertUnique($valueIds, $valueId, 'value');
                $normalizedValues[] = [
                    'id' => $valueId,
                    'label' => $this->requiredLabel($value['label'] ?? null, 'value'),
                ];
            }

            $normalized[] = [
                'id' => $id,
                'label' => $this->requiredLabel($option['label'] ?? null, 'option'),
                'values' => $normalizedValues,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $options
     * @return list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>
     */
    private function variants(mixed $variants, array $options): array
    {
        if (is_array($variants) === false || array_is_list($variants) === false) {
            throw new InvalidArgumentException('Variants must be a list.');
        }

        $knownValues = [];

        foreach ($options as $option) {
            $knownValues[$option['id']] = array_column($option['values'], 'id');
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

            if (count($selectedOptions) !== count($normalizedOptions)) {
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

            $normalized[] = [
                'id' => $id,
                'selectedOptions' => $normalizedOptions,
                'enabled' => $enabled,
                'sku' => $this->nullableString($variant['sku'] ?? null, 'sku'),
                'price' => $this->nullableString($variant['price'] ?? null, 'price'),
                'stripePriceId' => $this->nullableString(
                    $variant['stripePriceId'] ?? null,
                    'stripePriceId',
                ),
                'requiresShipping' => $shipping,
            ];
        }

        return $normalized;
    }

    private function requiredId(mixed $value, string $kind): string
    {
        if (is_string($value) === false || preg_match('/^[A-Za-z0-9_-]{4,64}$/', $value) !== 1) {
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
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException(sprintf('Each %s requires a label.', $kind));
        }

        return $value;
    }

    private function optionalLabel(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
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
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
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
                throw new InvalidArgumentException('Variant data requires named root properties.');
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
