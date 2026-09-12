<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldOption;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Collection\Exception\InvalidCustomFieldException;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Normalizes configured custom fields and creates their localized public values.
 * @internal
 */
final class CustomFieldFactory
{
    private const FIELD_KEYS = [
        'key',
        'label',
        'labels',
        'type',
        'required',
        'minimumLength',
        'maximumLength',
        'defaultValue',
        'options',
    ];

    private const OPTION_KEYS = ['value', 'label', 'labels'];

    public function __construct(
        private readonly ?string $languageCode = null,
    ) {}

    /**
     * @param array<mixed, mixed> $fields
     * @return list<array<string, mixed>>
     */
    public function normalize(array $fields): array
    {
        // Stripe Checkout accepts at most three custom fields per Session.
        // https://docs.stripe.com/api/checkout/sessions/create?query=custom_fields
        if (array_is_list($fields) === false || count($fields) > 3) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.customFields');
        }

        $normalized = [];
        $keys = [];

        foreach ($fields as $index => $field) {
            $path = 'settings.customFields.' . $index;

            if (is_array($field) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path);
            }

            $this->assertKnownKeys($field, self::FIELD_KEYS, $path);

            foreach (['key', 'label', 'type'] as $requiredName) {
                if (array_key_exists($requiredName, $field) === false) {
                    throw new ConfigurationException(
                        'configuration.required_missing',
                        $path . '.' . $requiredName,
                    );
                }
            }

            $key = $this->identifier($field['key'], $path . '.key');

            if (isset($keys[$key])) {
                throw new ConfigurationException('configuration.value_invalid', $path . '.key');
            }

            $keys[$key] = true;
            $type = $field['type'];

            if (is_string($type) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path . '.type');
            }

            $customFieldType = CustomFieldType::tryFrom($type);

            if ($customFieldType === null) {
                throw new ConfigurationException('configuration.value_invalid', $path . '.type');
            }

            $required = $field['required'] ?? false;

            if (is_bool($required) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path . '.required');
            }

            $defaultValue = $field['defaultValue'] ?? null;

            if ($defaultValue !== null && is_string($defaultValue) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path . '.defaultValue');
            }

            $rawOptions = $field['options'] ?? [];

            if (is_array($rawOptions) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path . '.options');
            }

            $normalizedField = [
                'key' => $key,
                'label' => $this->label($field['label'], 50, $path . '.label'),
                'labels' => $this->labels($field['labels'] ?? [], 50, $path . '.labels'),
                'type' => $customFieldType->value,
                'required' => $required,
                'minimumLength' => $this->length($field['minimumLength'] ?? null, $path . '.minimumLength'),
                'maximumLength' => $this->length($field['maximumLength'] ?? null, $path . '.maximumLength'),
                'defaultValue' => $defaultValue,
                'options' => $this->normalizeOptions($rawOptions, $path . '.options'),
            ];

            // The domain constructor owns constraints that depend on several
            // fields, such as dropdown defaults and compatible length bounds.
            $this->create($normalizedField, $path);
            $normalized[] = $normalizedField;
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $fields
     * @return list<CustomField>
     */
    public function createAll(array $fields): array
    {
        $customFields = [];
        $fields = $this->normalize($fields);

        foreach ($fields as $index => $field) {
            $customFields[] = $this->create($field, 'settings.customFields.' . $index);
        }

        return $customFields;
    }

    /**
     * @param array<mixed, mixed> $field
     */
    private function create(array $field, string $path): CustomField
    {
        $key = $field['key'] ?? null;
        $label = $field['label'] ?? null;
        $labels = $field['labels'] ?? null;
        $type = $field['type'] ?? null;
        $required = $field['required'] ?? null;
        $minimumLength = $field['minimumLength'] ?? null;
        $maximumLength = $field['maximumLength'] ?? null;
        $defaultValue = $field['defaultValue'] ?? null;
        $rawOptions = $field['options'] ?? null;

        if (
            is_string($key) === false
            || is_string($label) === false
            || is_array($labels) === false
            || is_string($type) === false
            || is_bool($required) === false
            || ($minimumLength !== null && is_int($minimumLength) === false)
            || ($maximumLength !== null && is_int($maximumLength) === false)
            || ($defaultValue !== null && is_string($defaultValue) === false)
            || is_array($rawOptions) === false
        ) {
            throw new ConfigurationException('configuration.type_invalid', $path);
        }

        $customFieldType = CustomFieldType::tryFrom($type);

        if ($customFieldType === null) {
            throw new ConfigurationException('configuration.value_invalid', $path . '.type');
        }

        /** @var array<string, string> $labels */
        try {
            return new CustomField(
                key: $key,
                label: $this->localizedLabel($label, $labels),
                type: $customFieldType,
                required: $required,
                minimumLength: $minimumLength,
                maximumLength: $maximumLength,
                defaultValue: $defaultValue,
                options: $this->createOptions($rawOptions, $path . '.options'),
            );
        } catch (InvalidCustomFieldException $error) {
            throw new ConfigurationException('configuration.value_invalid', $path . '.' . $error->attribute());
        }
    }

    /**
     * @param array<mixed, mixed> $options
     * @return list<array{value: string, label: string, labels: array<string, string>}>
     */
    private function normalizeOptions(array $options, string $path): array
    {
        if (array_is_list($options) === false || count($options) > 200) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        $normalized = [];
        $values = [];

        foreach ($options as $index => $option) {
            $optionPath = $path . '.' . $index;

            if (is_array($option) === false) {
                throw new ConfigurationException('configuration.type_invalid', $optionPath);
            }

            $this->assertKnownKeys($option, self::OPTION_KEYS, $optionPath);

            foreach (['value', 'label'] as $requiredName) {
                if (array_key_exists($requiredName, $option) === false) {
                    throw new ConfigurationException(
                        'configuration.required_missing',
                        $optionPath . '.' . $requiredName,
                    );
                }
            }

            $value = $this->identifier($option['value'], $optionPath . '.value');

            if (isset($values[$value])) {
                throw new ConfigurationException('configuration.value_invalid', $optionPath . '.value');
            }

            $values[$value] = true;
            $normalized[] = [
                'value' => $value,
                'label' => $this->label($option['label'], 100, $optionPath . '.label'),
                'labels' => $this->labels($option['labels'] ?? [], 100, $optionPath . '.labels'),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $options
     * @return list<CustomFieldOption>
     */
    private function createOptions(array $options, string $path): array
    {
        $values = [];

        foreach ($options as $index => $option) {
            if (
                is_array($option) === false
                || is_string($option['value'] ?? null) === false
                || is_string($option['label'] ?? null) === false
                || is_array($option['labels'] ?? null) === false
            ) {
                throw new ConfigurationException('configuration.type_invalid', $path . '.' . $index);
            }

            /** @var array<string, string> $labels */
            $labels = $option['labels'];

            try {
                $values[] = new CustomFieldOption(
                    value: $option['value'],
                    label: $this->localizedLabel($option['label'], $labels),
                );
            } catch (InvalidCustomFieldException $error) {
                throw new ConfigurationException(
                    'configuration.value_invalid',
                    $path . '.' . $index . '.' . $error->attribute(),
                );
            }
        }

        return $values;
    }

    private function identifier(mixed $value, string $path): string
    {
        if (is_string($value) === false) {
            throw new ConfigurationException('configuration.type_invalid', $path);
        }

        if (preg_match('/\A[a-z0-9]{1,64}\z/D', $value) !== 1) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        return $value;
    }

    private function label(mixed $value, int $maximumLength, string $path): string
    {
        if (is_string($value) === false) {
            throw new ConfigurationException('configuration.type_invalid', $path);
        }

        if (
            $value === ''
            || trim($value) !== $value
            || TextValidator::isSingleLine($value) === false
            || mb_strlen($value) > $maximumLength
        ) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        return $value;
    }

    /** @return array<string, string> */
    private function labels(mixed $labels, int $maximumLength, string $path): array
    {
        if (is_array($labels) === false || array_is_list($labels) && $labels !== []) {
            throw new ConfigurationException('configuration.type_invalid', $path);
        }

        $normalized = [];

        foreach ($labels as $languageCode => $label) {
            if (
                is_string($languageCode) === false
                || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $languageCode) !== 1
            ) {
                throw new ConfigurationException('configuration.value_invalid', $path);
            }

            $normalized[$languageCode] = $this->label(
                $label,
                $maximumLength,
                $path . '.' . $languageCode,
            );
        }

        ksort($normalized);

        return $normalized;
    }

    private function length(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) === false) {
            throw new ConfigurationException('configuration.type_invalid', $path);
        }

        if ($value < 1 || $value > 255) {
            throw new ConfigurationException('configuration.value_invalid', $path);
        }

        return $value;
    }

    /** @param array<string, string> $labels */
    private function localizedLabel(string $fallback, array $labels): string
    {
        return $this->languageCode === null
            ? $fallback
            : $labels[$this->languageCode] ?? $fallback;
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $knownKeys
     */
    private function assertKnownKeys(array $values, array $knownKeys, string $parent): void
    {
        foreach (array_keys($values) as $key) {
            if (is_string($key) && in_array($key, $knownKeys, true)) {
                continue;
            }

            throw new ConfigurationException(
                'configuration.option_unknown',
                $parent . '.' . $key,
            );
        }
    }
}
