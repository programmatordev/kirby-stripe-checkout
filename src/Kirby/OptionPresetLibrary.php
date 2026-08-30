<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Content\Field;
use Kirby\Data\Yaml;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use Throwable;

/**
 * Reads the default-language, Settings-owned option preset copies.
 *
 * @internal
 */
final class OptionPresetLibrary
{
    public function __construct(private readonly App $kirby) {}

    /**
     * @return list<array{label: string, options: list<array{label: string, values: list<string>}>}>
     */
    public function all(): array
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->page();

        if ($page === null) {
            return [];
        }

        $field = $page->content($this->kirby->defaultLanguage()?->code())->get('optionPresets');
        $value = $field instanceof Field ? $field->value() : null;

        if ($value === null || $value === '') {
            return [];
        }

        try {
            $presets = is_array($value) ? $value : Yaml::decode($value);
        } catch (Throwable $error) {
            throw new ConfigurationException(
                'persistence.content_invalid',
                'optionPresets',
                $error,
            );
        }

        if (array_is_list($presets) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
        }

        $normalized = [];

        foreach ($presets as $preset) {
            if (is_array($preset) === false) {
                throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
            }

            $label = $this->requiredLabel($preset['label'] ?? null);
            $options = $this->decodeList($preset['options'] ?? null);
            $normalizedOptions = [];

            foreach ($options as $option) {
                if (is_array($option) === false) {
                    throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
                }

                $values = $this->decodeValues($option['values'] ?? null);

                if ($values === []) {
                    throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
                }

                $normalizedOptions[] = [
                    'label' => $this->requiredLabel($option['label'] ?? null),
                    'values' => $values,
                ];
            }

            if ($normalizedOptions === []) {
                throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
            }

            $normalized[] = ['label' => $label, 'options' => $normalizedOptions];
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
                    'optionPresets',
                    $error,
                );
            }
        }

        if (is_array($value) === false || array_is_list($value) === false) {
            throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
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
            throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
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
            throw new ConfigurationException('persistence.content_invalid', 'optionPresets');
        }

        return trim($value);
    }
}
