<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use InvalidArgumentException;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use Throwable;

/**
 * Stores shipping zones canonically and option-label translations by stable ID.
 *
 * @internal
 * @phpstan-type CanonicalOption array{id: string, key: mixed, label: mixed, amount: mixed, deliveryEstimate: mixed, taxBehavior?: mixed, taxCode?: mixed}
 * @phpstan-type CanonicalZone array{id: string, name: mixed, scope: mixed, countries: mixed, options: list<CanonicalOption>}
 * @phpstan-type OverlayOption array{id: string, label: string}
 * @phpstan-type OverlayZone array{id: string, options: list<OverlayOption>}
 */
final class ShippingZoneStructureAdapter implements SynchronizedStructureAdapterInterface
{
    private const ZONE_KEYS = ['_id', 'id', 'name', 'scope', 'countries', 'options'];

    private const OPTION_KEYS = [
        '_id',
        'id',
        'key',
        'label',
        'amount',
        'deliveryEstimate',
        'taxBehavior',
        'taxCode',
    ];

    /** @return list<CanonicalZone> */
    public function canonical(mixed $value): array
    {
        $zones = [];
        $zoneIds = [];

        foreach ($this->decode($value) as $zoneIndex => $zone) {
            $path = 'shippingZones.' . $zoneIndex;
            $this->assertKnownKeys($zone, self::ZONE_KEYS, $path);
            $id = $this->stableId($zone, $path);
            $this->assertUnique($zoneIds, $id, $path . '.id');
            $options = [];
            $optionIds = [];

            foreach ($this->rows($zone['options'] ?? null) as $optionIndex => $option) {
                $optionPath = $path . '.options.' . $optionIndex;
                $this->assertKnownKeys($option, self::OPTION_KEYS, $optionPath);
                $optionId = $this->stableId($option, $optionPath);
                $this->assertUnique($optionIds, $optionId, $optionPath . '.id');
                $options[] = [
                    'id' => $optionId,
                    'key' => $option['key'] ?? null,
                    'label' => $option['label'] ?? null,
                    'amount' => $option['amount'] ?? null,
                    'deliveryEstimate' => $option['deliveryEstimate'] ?? null,
                    ...array_key_exists('taxBehavior', $option)
                        ? ['taxBehavior' => $option['taxBehavior']]
                        : [],
                    ...array_key_exists('taxCode', $option)
                        ? ['taxCode' => $option['taxCode']]
                        : [],
                ];
            }

            $zones[] = [
                'id' => $id,
                'name' => $zone['name'] ?? null,
                'scope' => $zone['scope'] ?? null,
                'countries' => $zone['countries'] ?? [],
                'options' => $options,
            ];
        }

        return $zones;
    }

    /**
     * @param list<CanonicalZone> $canonical
     * @return list<CanonicalZone>
     */
    public function localized(array $canonical, mixed $overlay): array
    {
        /** @var array<string, OverlayZone> $overlays */
        $overlays = $this->indexById($this->overlay($canonical, $overlay));
        $localized = [];

        foreach ($canonical as $zone) {
            /** @var array<string, OverlayOption> $translatedOptions */
            $translatedOptions = $this->indexById($overlays[$zone['id']]['options'] ?? []);
            $options = [];

            foreach ($zone['options'] as $option) {
                $translated = $translatedOptions[$option['id']]['label'] ?? null;
                $options[] = [
                    ...$option,
                    'label' => is_string($translated) && $translated !== ''
                        ? $translated
                        : $option['label'],
                ];
            }

            $localized[] = [...$zone, 'options' => $options];
        }

        return $localized;
    }

    /**
     * @param list<CanonicalZone> $canonical
     * @return list<OverlayZone>
     */
    public function overlay(array $canonical, mixed $value): array
    {
        $submittedZones = $this->indexSubmittedRows($this->decode($value));
        $overlay = [];

        foreach ($canonical as $zone) {
            $submittedZone = $submittedZones[$zone['id']] ?? [];
            $submittedOptions = $this->indexSubmittedRows(
                $this->submittedRows($submittedZone['options'] ?? null),
            );
            $options = [];

            foreach ($zone['options'] as $option) {
                $submittedLabel = $submittedOptions[$option['id']]['label'] ?? null;
                $label = $this->overlayLabel(
                    $submittedLabel,
                    $option['label'],
                    'shippingZones.options.label',
                );

                $options[] = [
                    'id' => $option['id'],
                    'label' => $label,
                ];
            }

            $overlay[] = [
                'id' => $zone['id'],
                'options' => $options,
            ];
        }

        return $overlay;
    }

    /**
     * @param list<CanonicalZone> $localized
     * @return list<array<string, mixed>>
     */
    public function definitions(array $localized): array
    {
        $definitions = [];

        foreach ($localized as $zone) {
            $options = [];

            foreach ($zone['options'] as $option) {
                $definition = [
                    'key' => $option['key'],
                    'label' => $option['label'],
                    'amount' => $option['amount'],
                    'deliveryEstimate' => $option['deliveryEstimate'],
                ];

                if (array_key_exists('taxBehavior', $option)) {
                    $definition['taxBehavior'] = $option['taxBehavior'];
                }

                if (array_key_exists('taxCode', $option)) {
                    $definition['taxCode'] = $option['taxCode'];
                }

                $options[] = $definition;
            }

            $definitions[] = [
                'name' => $zone['name'],
                'scope' => $zone['scope'],
                'countries' => $zone['countries'],
                'options' => $options,
            ];
        }

        return $definitions;
    }

    /** @return list<array<string, mixed>> */
    private function decode(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            try {
                $value = Yaml::decode($value);
            } catch (Throwable $error) {
                throw new InvalidArgumentException(
                    'Synchronized shipping-zone data contains invalid YAML.',
                    previous: $error,
                );
            }
        }

        return $this->rows($value);
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw new InvalidArgumentException('Synchronized shipping-zone data must be a list.');
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) === false) {
                throw new InvalidArgumentException('Each synchronized shipping-zone row must be an object.');
            }

            /** @var array<string, mixed> $row */
            $rows[] = $row;
        }

        return $rows;
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
            throw new InvalidArgumentException('A synchronized shipping-zone row has an invalid ID at ' . $path . '.');
        }

        return bin2hex(random_bytes(8));
    }

    /** @param array<string, mixed> $row */
    private function submittedId(array $row): ?string
    {
        // Stored plugin rows use `id`; Kirby's native Structure transport uses
        // `_id`. Accept either form, but never guess between two identities.
        $id = $row['id'] ?? null;
        $kirbyId = $row['_id'] ?? null;

        if ($id !== null && $kirbyId !== null && $id !== $kirbyId) {
            throw new InvalidArgumentException('A synchronized shipping-zone row has conflicting IDs.');
        }

        $id ??= $kirbyId;

        return is_string($id) && preg_match('/\A[a-z0-9]{16}\z/D', $id) === 1
            ? $id
            : null;
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
     * Unknown and malformed IDs are ignored because a translation overlay
     * cannot create canonical membership. Duplicate known IDs are ambiguous.
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
                throw new InvalidArgumentException('A translated shipping-zone row ID must be unique.');
            }

            $indexed[$id] = $row;
        }

        return $indexed;
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

            throw new InvalidArgumentException('Unknown shipping-zone property at ' . $path . '.');
        }
    }

    /** @param array<string, true> $seen */
    private function assertUnique(array &$seen, string $value, string $path): void
    {
        if (isset($seen[$value])) {
            throw new InvalidArgumentException('Duplicate shipping-zone value at ' . $path . '.');
        }

        $seen[$value] = true;
    }

    private function overlayLabel(mixed $value, mixed $fallback, string $path): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (
            is_string($value) === false
            || trim($value) !== $value
            || TextValidator::isSingleLine($value) === false
            || mb_strlen($value) > 100
        ) {
            throw new InvalidArgumentException('A translated shipping-option label is invalid at ' . $path . '.');
        }

        // The translated form displays the canonical label as its fallback.
        // Keep it sparse so later default-language edits still flow through.
        return is_string($fallback) && $value === $fallback ? '' : $value;
    }
}
