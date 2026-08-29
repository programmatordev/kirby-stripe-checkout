<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Prototype;

use InvalidArgumentException;
use Kirby\Data\Yaml;

/**
 * Normalizes the prototype's canonical data and translated label overlays.
 *
 * @internal The storage format is intentionally not a public plugin contract.
 */
final class VariantSchema
{
    /**
     * @return array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    public function canonical(mixed $value): array
    {
        $data = $this->decode($value);
        $groups = $this->groups($data['groups'] ?? []);
        $variants = $this->variants($data['variants'] ?? [], $groups);

        return [
            'groups' => $groups,
            'variants' => (new VariantMatrix())->reconcile($groups, $variants),
        ];
    }

    /**
     * @param array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @return array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>}
     */
    public function overlay(array $canonical, mixed $value): array
    {
        $input = $this->decode($value);
        $submittedGroups = is_array($input['groups'] ?? null) ? $input['groups'] : [];
        $submittedById = [];

        foreach ($submittedGroups as $submittedGroup) {
            if (is_array($submittedGroup) && is_string($submittedGroup['id'] ?? null)) {
                $submittedById[$submittedGroup['id']] = $submittedGroup;
            }
        }

        $groups = [];

        foreach ($canonical['groups'] as $group) {
            $submittedGroup = $submittedById[$group['id']] ?? [];
            $submittedValues = is_array($submittedGroup['values'] ?? null)
                ? $submittedGroup['values']
                : [];
            $submittedValuesById = [];

            foreach ($submittedValues as $submittedValue) {
                if (is_array($submittedValue) && is_string($submittedValue['id'] ?? null)) {
                    $submittedValuesById[$submittedValue['id']] = $submittedValue;
                }
            }

            $values = [];

            foreach ($group['values'] as $valueDefinition) {
                $submittedValue = $submittedValuesById[$valueDefinition['id']] ?? [];
                $values[] = [
                    'id' => $valueDefinition['id'],
                    'label' => $this->optionalLabel($submittedValue['label'] ?? null),
                ];
            }

            $groups[] = [
                'id' => $group['id'],
                'label' => $this->optionalLabel($submittedGroup['label'] ?? null),
                'values' => $values,
            ];
        }

        return ['groups' => $groups];
    }

    /**
     * @param array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @return array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    public function localized(array $canonical, mixed $overlay): array
    {
        $overlayData = $this->decode($overlay);
        $overlayGroups = is_array($overlayData['groups'] ?? null) ? $overlayData['groups'] : [];
        $groupsById = [];

        foreach ($overlayGroups as $group) {
            if (is_array($group) && is_string($group['id'] ?? null)) {
                $groupsById[$group['id']] = $group;
            }
        }

        $localizedGroups = [];

        foreach ($canonical['groups'] as $group) {
            $overlayGroup = $groupsById[$group['id']] ?? [];
            $valuesById = [];

            foreach (is_array($overlayGroup['values'] ?? null) ? $overlayGroup['values'] : [] as $value) {
                if (is_array($value) && is_string($value['id'] ?? null)) {
                    $valuesById[$value['id']] = $value;
                }
            }

            $values = [];

            foreach ($group['values'] as $value) {
                $label = $this->optionalLabel($valuesById[$value['id']]['label'] ?? null);
                $values[] = [...$value, 'label' => $label === '' ? $value['label'] : $label];
            }

            $label = $this->optionalLabel($overlayGroup['label'] ?? null);
            $localizedGroups[] = [
                ...$group,
                'label' => $label === '' ? $group['label'] : $label,
                'values' => $values,
            ];
        }

        return [...$canonical, 'groups' => $localizedGroups];
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
    private function groups(mixed $groups): array
    {
        if (is_array($groups) === false || array_is_list($groups) === false) {
            throw new InvalidArgumentException('Variant groups must be a list.');
        }

        $normalized = [];
        $ids = [];

        foreach ($groups as $group) {
            if (is_array($group) === false) {
                throw new InvalidArgumentException('Each variant group must be an object.');
            }

            $id = $this->requiredId($group['id'] ?? null, 'group');
            $this->assertUnique($ids, $id, 'group');
            $values = $group['values'] ?? null;

            if (is_array($values) === false || array_is_list($values) === false || $values === []) {
                throw new InvalidArgumentException('Each variant group must contain at least one value.');
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
                'label' => $this->requiredLabel($group['label'] ?? null, 'group'),
                'values' => $normalizedValues,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $groups
     * @return list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>
     */
    private function variants(mixed $variants, array $groups): array
    {
        if (is_array($variants) === false || array_is_list($variants) === false) {
            throw new InvalidArgumentException('Variants must be a list.');
        }

        $knownValues = [];

        foreach ($groups as $group) {
            $knownValues[$group['id']] = array_column($group['values'], 'id');
        }

        $normalized = [];
        $ids = [];
        $choiceKeys = [];

        foreach ($variants as $variant) {
            if (is_array($variant) === false) {
                throw new InvalidArgumentException('Each variant must be an object.');
            }

            $id = $this->requiredId($variant['id'] ?? null, 'variant');
            $this->assertUnique($ids, $id, 'variant');
            $choices = $variant['choices'] ?? null;

            if (is_array($choices) === false) {
                throw new InvalidArgumentException('Each variant must define its choices.');
            }

            $normalizedChoices = [];

            foreach ($knownValues as $groupId => $valueIds) {
                $valueId = $choices[$groupId] ?? null;

                if (is_string($valueId) === false || in_array($valueId, $valueIds, true) === false) {
                    throw new InvalidArgumentException('A variant references an unknown group value.');
                }

                $normalizedChoices[$groupId] = $valueId;
            }

            if (count($choices) !== count($normalizedChoices)) {
                throw new InvalidArgumentException('A variant contains an unknown group choice.');
            }

            $choiceKey = VariantMatrix::choiceKey($normalizedChoices);
            $this->assertUnique($choiceKeys, $choiceKey, 'combination');
            $shipping = $variant['requiresShipping'] ?? 'inherit';

            if (in_array($shipping, ['inherit', 'yes', 'no'], true) === false) {
                throw new InvalidArgumentException('A variant has an invalid shipping override.');
            }

            $normalized[] = [
                'id' => $id,
                'choices' => $normalizedChoices,
                'enabled' => ($variant['enabled'] ?? true) === true,
                'sku' => $this->nullableString($variant['sku'] ?? null),
                'price' => $this->nullableString($variant['price'] ?? null),
                'stripePriceId' => $this->nullableString($variant['stripePriceId'] ?? null),
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
        if (is_string($value) === false || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Each %s requires a label.', $kind));
        }

        return trim($value);
    }

    private function optionalLabel(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('Variant commerce values must be strings.');
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
