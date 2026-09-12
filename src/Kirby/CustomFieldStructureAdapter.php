<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use InvalidArgumentException;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Configuration\CustomFieldFactory;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/**
 * Adapts Panel Structure rows to canonical custom-field definitions.
 *
 * Internal row IDs connect translations without becoming part of the public
 * custom-field API. Stable field keys and dropdown values remain commerce data.
 *
 * @internal
 * @phpstan-type CanonicalOption array{id: string, value: string, label: string}
 * @phpstan-type CanonicalField array{id: string, key: string, label: string, type: string, required: bool, minimumLength: ?int, maximumLength: ?int, defaultValue: ?string, options: list<CanonicalOption>}
 * @phpstan-type OverlayOption array{id: string, label: string}
 * @phpstan-type OverlayField array{id: string, label: string, options: list<OverlayOption>}
 */
final class CustomFieldStructureAdapter implements SynchronizedStructureAdapterInterface
{
    private const FIELD_KEYS = [
        '_id',
        'id',
        'key',
        'label',
        'type',
        'required',
        'minimumLength',
        'maximumLength',
        'defaultValue',
        'options',
    ];

    private const OPTION_KEYS = [
        '_id',
        'id',
        'value',
        'label',
    ];

    /** @return list<CanonicalField> */
    public function canonical(mixed $value): array
    {
        $rows = $this->decode($value);

        // Stripe Checkout accepts at most three custom fields per Session.
        // https://docs.stripe.com/api/checkout/sessions/create?query=custom_fields
        if (count($rows) > 3) {
            throw new InvalidArgumentException('Checkout accepts at most three custom fields.');
        }

        $canonical = [];
        $definitions = [];
        $rowIds = [];

        foreach ($rows as $index => $row) {
            $path = 'customFields.' . $index;
            $this->assertKnownKeys($row, self::FIELD_KEYS, $path);
            $id = $this->stableId($row, $path);
            $this->assertUnique($rowIds, $id, $path . '.id');
            $key = $this->requiredString($row['key'] ?? null, $path . '.key');
            $type = $this->requiredString($row['type'] ?? null, $path . '.type');

            $options = $this->canonicalOptions($row['options'] ?? [], $path . '.options');
            $definition = [
                'key' => $key,
                'label' => $this->requiredLabel($row['label'] ?? null, 50, $path . '.label'),
                'type' => $type,
                'required' => $this->boolean($row['required'] ?? false, $path . '.required'),
                'minimumLength' => $this->length($row['minimumLength'] ?? null, $path . '.minimumLength'),
                'maximumLength' => $this->length($row['maximumLength'] ?? null, $path . '.maximumLength'),
                'defaultValue' => $this->optionalString($row['defaultValue'] ?? null, $path . '.defaultValue'),
                'options' => array_map(
                    static fn(array $option): array => [
                        'value' => $option['value'],
                        'label' => $option['label'],
                    ],
                    $options,
                ),
            ];

            $definitions[] = $definition;
            $canonical[] = [
                'id' => $id,
                ...$definition,
                'options' => $options,
            ];
        }

        $this->validateDefinitions($definitions);

        return $canonical;
    }

    /**
     * @param list<CanonicalField> $canonical
     * @return list<CanonicalField>
     */
    public function localized(array $canonical, mixed $overlay): array
    {
        $overlayRows = $this->overlay($canonical, $overlay);
        /** @var array<string, OverlayField> $overlaysById */
        $overlaysById = $this->indexById($overlayRows);
        $localized = [];

        foreach ($canonical as $row) {
            $translated = $overlaysById[$row['id']] ?? [];
            /** @var array<string, OverlayOption> $translatedOptions */
            $translatedOptions = $this->indexById($translated['options'] ?? []);
            $options = [];

            foreach ($row['options'] as $option) {
                $translatedOption = $translatedOptions[$option['id']] ?? [];
                $options[] = [
                    ...$option,
                    'label' => $this->fallbackLabel($translatedOption['label'] ?? null, $option['label']),
                ];
            }

            $localized[] = [
                ...$row,
                'label' => $this->fallbackLabel($translated['label'] ?? null, $row['label']),
                'options' => $options,
            ];
        }

        return $localized;
    }

    /**
     * @param list<CanonicalField> $canonical
     * @return list<OverlayField>
     */
    public function overlay(array $canonical, mixed $value): array
    {
        $submittedRows = $this->indexSubmittedRows($this->decode($value));
        $overlay = [];

        foreach ($canonical as $row) {
            $submitted = $submittedRows[$row['id']] ?? [];
            $submittedOptions = $this->indexSubmittedRows(
                $this->submittedRows($submitted['options'] ?? null),
            );
            $options = [];

            foreach ($row['options'] as $option) {
                $submittedOption = $submittedOptions[$option['id']] ?? [];
                $options[] = [
                    'id' => $option['id'],
                    'label' => $this->overlayLabel(
                        $submittedOption['label'] ?? null,
                        $option['label'],
                        100,
                        'customFields.options.label',
                    ),
                ];
            }

            $overlay[] = [
                'id' => $row['id'],
                'label' => $this->overlayLabel(
                    $submitted['label'] ?? null,
                    $row['label'],
                    50,
                    'customFields.label',
                ),
                'options' => $options,
            ];
        }

        return $overlay;
    }

    /**
     * Removes synchronization IDs from a localized canonical value.
     *
     * @param list<CanonicalField> $localized
     * @return list<array<string, mixed>>
     */
    public function definitions(array $localized): array
    {
        $definitions = [];

        foreach ($localized as $row) {
            $options = [];

            foreach ($row['options'] as $option) {
                $options[] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                ];
            }

            $definitions[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'type' => $row['type'],
                'required' => $row['required'],
                'minimumLength' => $row['minimumLength'],
                'maximumLength' => $row['maximumLength'],
                'defaultValue' => $row['defaultValue'],
                'options' => $options,
            ];
        }

        return $definitions;
    }

    /**
     * @return list<CanonicalOption>
     */
    private function canonicalOptions(mixed $value, string $path): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        // Stripe limits dropdown custom fields to 200 options.
        // https://docs.stripe.com/api/checkout/sessions/create?query=custom_fields
        if (is_array($value) === false || array_is_list($value) === false || count($value) > 200) {
            throw new InvalidArgumentException('Custom-field options must be a list of at most 200 entries.');
        }

        $options = [];
        $ids = [];

        /** @var list<mixed> $value */
        foreach ($value as $index => $option) {
            $optionPath = $path . '.' . $index;

            if (is_array($option) === false) {
                throw new InvalidArgumentException('Each custom-field option must be an object.');
            }

            /** @var array<string, mixed> $option */
            $this->assertKnownKeys($option, self::OPTION_KEYS, $optionPath);
            $id = $this->stableId($option, $optionPath);
            $this->assertUnique($ids, $id, $optionPath . '.id');
            $optionValue = $this->requiredString($option['value'] ?? null, $optionPath . '.value');
            $options[] = [
                'id' => $id,
                'value' => $optionValue,
                'label' => $this->requiredLabel($option['label'] ?? null, 100, $optionPath . '.label'),
            ];
        }

        return $options;
    }

    /** @return list<array<string, mixed>> */
    private function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $value = Yaml::decode($value);
        }

        if (is_array($value) === false || array_is_list($value) === false) {
            throw new InvalidArgumentException('Synchronized Structure data must be a list.');
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) === false) {
                throw new InvalidArgumentException('Each synchronized Structure row must be an object.');
            }

            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @template T of array{id: string}
     * @param list<T> $rows
     * @return array<string, T>
     */
    private function indexById(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['id']] = $row;
        }

        return $indexed;
    }

    /**
     * Unknown and malformed IDs are ignored because an overlay cannot create
     * canonical membership. Duplicate known IDs are rejected as ambiguous.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function indexSubmittedRows(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $id = $this->submittedId($row);

            if ($id === null) {
                continue;
            }

            if (isset($indexed[$id])) {
                throw new InvalidArgumentException('A translated Structure row ID must be unique.');
            }

            $indexed[$id] = $row;
        }

        return $indexed;
    }

    /** @return list<array<string, mixed>> */
    private function submittedRows(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function stableId(array $row, string $path): string
    {
        $id = $this->submittedId($row);

        if ($id !== null) {
            return $id;
        }

        if (array_key_exists('id', $row) || array_key_exists('_id', $row)) {
            throw new InvalidArgumentException('A synchronized Structure row has an invalid ID at ' . $path . '.');
        }

        return bin2hex(random_bytes(8));
    }

    /** @param array<string, mixed> $row */
    private function submittedId(array $row): ?string
    {
        $id = $row['id'] ?? $row['_id'] ?? null;

        return is_string($id) && preg_match('/\A[a-z0-9]{16}\z/D', $id) === 1
            ? $id
            : null;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $knownKeys
     */
    private function assertKnownKeys(array $values, array $knownKeys, string $path): void
    {
        foreach (array_keys($values) as $key) {
            if (in_array($key, $knownKeys, true)) {
                continue;
            }

            throw new InvalidArgumentException('Unknown custom-field property at ' . $path . '.');
        }
    }

    /** @param array<string, true> $seen */
    private function assertUnique(array &$seen, string $value, string $path): void
    {
        if (isset($seen[$value])) {
            throw new InvalidArgumentException('Duplicate custom-field value at ' . $path . '.');
        }

        $seen[$value] = true;
    }

    private function requiredString(mixed $value, string $path): string
    {
        if (is_string($value) === false || $value === '') {
            throw new InvalidArgumentException('A custom-field value is required at ' . $path . '.');
        }

        return $value;
    }

    private function optionalString(mixed $value, string $path): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('A custom-field value must be text at ' . $path . '.');
        }

        return $value;
    }

    private function requiredLabel(mixed $value, int $maximumLength, string $path): string
    {
        if (is_string($value) === false || $value === '') {
            throw new InvalidArgumentException('A custom-field label is required at ' . $path . '.');
        }

        return $this->validateLabel($value, $maximumLength, $path);
    }

    private function optionalLabel(mixed $value, int $maximumLength, string $path): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_string($value) === false) {
            throw new InvalidArgumentException('A translated label must be text at ' . $path . '.');
        }

        return $this->validateLabel($value, $maximumLength, $path);
    }

    private function overlayLabel(
        mixed $value,
        string $fallback,
        int $maximumLength,
        string $path,
    ): string {
        $label = $this->optionalLabel($value, $maximumLength, $path);

        // The Panel displays fallback labels in translated forms. Avoid storing
        // those unchanged values as explicit translations so later edits to the
        // default language continue to flow through.
        return $label === $fallback ? '' : $label;
    }

    private function validateLabel(string $value, int $maximumLength, string $path): string
    {
        if (
            trim($value) !== $value
            || TextValidator::isSingleLine($value) === false
            || mb_strlen($value) > $maximumLength
        ) {
            throw new InvalidArgumentException('A custom-field label is invalid at ' . $path . '.');
        }

        return $value;
    }

    private function fallbackLabel(mixed $translated, string $fallback): string
    {
        return is_string($translated) && $translated !== '' ? $translated : $fallback;
    }

    private function boolean(mixed $value, string $path): bool
    {
        return match ($value) {
            true, 'true', '1', 1 => true,
            false, 'false', '0', 0, null, '' => false,
            default => throw new InvalidArgumentException('A custom-field toggle is invalid at ' . $path . '.'),
        };
    }

    private function length(mixed $value, string $path): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) {
            $value = (int) $value;
        }

        if (is_int($value) === false || $value < 1 || $value > 255) {
            throw new InvalidArgumentException('A custom-field length is invalid at ' . $path . '.');
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $definitions */
    private function validateDefinitions(array $definitions): void
    {
        try {
            (new CustomFieldFactory())->normalize($definitions);
        } catch (ConfigurationException $error) {
            throw new InvalidArgumentException(
                'Custom-field data is invalid at ' . $error->path() . '.',
                previous: $error,
            );
        }
    }
}
