<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use SensitiveParameter;

/**
 * Converts Kirby's nested or fully dotted plugin options into one logical tree.
 *
 * @internal
 */
final class OptionExtractor
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    private const DOTTED_LEAVES = [
        'settings.priceSource',
        'stripe.publishableKey',
        'stripe.secretKey',
        'stripe.webhookSecret',
        'translations',
    ];

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function extract(#[SensitiveParameter] array $options): array
    {
        $root = array_key_exists(self::PREFIX, $options)
            ? $options[self::PREFIX]
            : [];

        if (is_array($root) === false) {
            throw new ConfigurationException('configuration.root_invalid');
        }

        foreach (array_keys($root) as $key) {
            if (is_string($key) === false) {
                throw new ConfigurationException('configuration.option_unknown', (string) $key);
            }
        }

        /** @var array<string, mixed> $root */
        $normalizedDottedValues = [];

        // Kirby can fold a fully dotted project option into the plugin root
        // while leaving its relative leaf dotted, so normalize that shape too.
        foreach ($root as $path => $value) {
            if (str_contains($path, '.') === false) {
                continue;
            }

            if (in_array($path, self::DOTTED_LEAVES, true) === false) {
                throw new ConfigurationException('configuration.option_unknown', $path);
            }

            $normalizedDottedValues[$path] = $value;
            unset($root[$path]);
        }

        $definedPaths = $this->definedNestedPaths($root);

        foreach ($normalizedDottedValues as $path => $value) {
            if (isset($definedPaths[$path])) {
                throw new ConfigurationException('configuration.option_duplicate', $path);
            }

            $this->setPath($root, $path, $value);
            $definedPaths[$path] = true;
        }

        foreach ($options as $key => $value) {
            if (str_starts_with($key, self::PREFIX . '.') === false) {
                continue;
            }

            $path = substr($key, strlen(self::PREFIX) + 1);

            if (in_array($path, self::DOTTED_LEAVES, true) === false) {
                throw new ConfigurationException('configuration.option_unknown', $path);
            }

            if (isset($definedPaths[$path])) {
                throw new ConfigurationException('configuration.option_duplicate', $path);
            }

            $this->setPath($root, $path, $value);
            $definedPaths[$path] = true;
        }

        return $root;
    }

    /**
     * @param array<string, mixed> $root
     * @return array<string, true>
     */
    private function definedNestedPaths(array $root): array
    {
        $paths = [];

        foreach ($root as $section => $value) {
            if (($section === 'settings' || $section === 'stripe') && is_array($value)) {
                foreach (array_keys($value) as $leaf) {
                    $paths[$section . '.' . (string) $leaf] = true;
                }

                continue;
            }

            $paths[(string) $section] = true;
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $root
     */
    private function setPath(
        #[SensitiveParameter]
        array &$root,
        string $path,
        #[SensitiveParameter]
        mixed $value,
    ): void {
        if ($path === 'translations') {
            $root[$path] = $value;

            return;
        }

        [$section, $leaf] = explode('.', $path, 2);

        $sectionOptions = $root[$section] ?? [];

        if (is_array($sectionOptions) === false) {
            throw new ConfigurationException('configuration.type_invalid', $section);
        }

        /** @var array<string, mixed> $sectionOptions */
        $sectionOptions[$leaf] = $value;
        $root[$section] = $sectionOptions;
    }
}
