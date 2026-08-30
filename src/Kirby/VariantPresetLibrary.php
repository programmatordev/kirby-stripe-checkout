<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use Throwable;

/**
 * Reads the default-language, Settings-owned variant preset copies.
 *
 * @internal
 */
final class VariantPresetLibrary
{
    public function __construct(private readonly App $kirby) {}

    /**
     * @return list<array{label: string, groups: list<array{label: string, values: list<string>}>}>
     */
    public function all(): array
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->page();

        if ($page === null) {
            return [];
        }

        $field = $page->content($this->kirby->defaultLanguage()?->code())->get('variantPresets');
        $value = $field instanceof Field ? $field->value() : null;

        if ($value === null || $value === '') {
            return [];
        }

        try {
            $presets = is_array($value) ? $value : Yaml::decode($value);
        } catch (Throwable $error) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'variantPresets',
                $error,
            );
        }

        if (array_is_list($presets) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
        }

        $normalized = [];

        foreach ($presets as $preset) {
            if (is_array($preset) === false) {
                throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
            }

            $label = $this->requiredLabel($preset['label'] ?? null);
            $groups = $this->decodeList($preset['groups'] ?? null);
            $normalizedGroups = [];

            foreach ($groups as $group) {
                if (is_array($group) === false) {
                    throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
                }

                $values = $this->decodeValues($group['values'] ?? null);

                if ($values === []) {
                    throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
                }

                $normalizedGroups[] = [
                    'label' => $this->requiredLabel($group['label'] ?? null),
                    'values' => $values,
                ];
            }

            if ($normalizedGroups === []) {
                throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
            }

            $normalized[] = ['label' => $label, 'groups' => $normalizedGroups];
        }

        return $normalized;
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = Yaml::decode($value);
            } catch (Throwable $error) {
                throw new ConfigurationException(
                    'persistence.content_invalid',
                    'variantPresets',
                    $error,
                );
            }
        }

        if (is_array($value) === false || array_is_list($value) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
        }

        return $value;
    }

    /** @return list<string> */
    private function decodeValues(mixed $value): array
    {
        if (is_string($value)) {
            // Kirby's Tags field stores its values as a comma-separated scalar
            // inside each nested Structure entry.
            $value = explode(',', $value);
        }

        if (is_array($value) === false || array_is_list($value) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
        }

        $normalized = [];

        foreach ($value as $label) {
            $label = $this->requiredLabel($label);
            $normalized[$label] = true;
        }

        return array_keys($normalized);
    }

    private function requiredLabel(mixed $value): string
    {
        if (is_string($value) === false || trim($value) === '' || strlen(trim($value)) > 500) {
            throw new ConfigurationException('persistence.content_invalid', 'variantPresets');
        }

        return trim($value);
    }
}
