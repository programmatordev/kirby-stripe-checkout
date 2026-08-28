<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use SensitiveParameter;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Validates operation inputs and resolves the initial immutable configuration.
 *
 * @internal
 */
final class ConfigurationResolver
{
    private const ROOT_KEYS = ['settings', 'stripe', 'translations'];
    private const SETTINGS_KEYS = ['priceSource'];
    private const STRIPE_KEYS = ['publishableKey', 'secretKey', 'webhookSecret'];

    public function __construct(
        private readonly OptionExtractor $extractor = new OptionExtractor(),
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function resolve(
        #[SensitiveParameter]
        array $options,
        ?PageSettings $pageSettings = null,
    ): ConfigurationReport {
        try {
            $root = $this->resolveRoot($this->extractor->extract($options));
            $stripe = $this->resolveStripe($root['stripe']);
            $settings = $this->resolveSettings($root['settings'], $pageSettings);
            $translations = $this->resolveTranslations($root['translations']);

            $this->validateCredentialCombination($stripe);

            return ConfigurationReport::valid(new ResolvedConfiguration(
                settings: $settings,
                stripe: $stripe,
                translations: $translations,
            ));
        } catch (ConfigurationException $error) {
            return ConfigurationReport::invalid($error);
        }
    }

    /**
     * @param array<string, mixed> $root
     * @return array{settings: array<string, mixed>, stripe: array<string, mixed>, translations: array<mixed, mixed>}
     */
    private function resolveRoot(#[SensitiveParameter] array $root): array
    {
        $this->assertKnownKeys($root, self::ROOT_KEYS);

        foreach (self::ROOT_KEYS as $key) {
            if (array_key_exists($key, $root) && is_array($root[$key]) === false) {
                throw new ConfigurationException('configuration.type_invalid', $key);
            }
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'settings' => [],
            'stripe' => [],
            'translations' => [],
        ]);
        $resolver->setAllowedTypes('settings', 'array');
        $resolver->setAllowedTypes('stripe', 'array');
        $resolver->setAllowedTypes('translations', 'array');

        /** @var array{settings: array<string, mixed>, stripe: array<string, mixed>, translations: array<mixed, mixed>} */
        return $resolver->resolve($root);
    }

    /**
     * @param array<string, mixed> $stripe
     */
    private function resolveStripe(#[SensitiveParameter] array $stripe): StripeConfiguration
    {
        $this->assertKnownKeys($stripe, self::STRIPE_KEYS, 'stripe');

        foreach (self::STRIPE_KEYS as $key) {
            if (array_key_exists($key, $stripe) === false) {
                continue;
            }

            $value = $stripe[$key];
            $path = 'stripe.' . $key;

            if ($value !== null && is_string($value) === false) {
                throw new ConfigurationException('configuration.type_invalid', $path);
            }

            if (is_string($value) && ($value === '' || trim($value) !== $value)) {
                throw new ConfigurationException('configuration.value_invalid', $path);
            }
        }

        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'publishableKey' => null,
            'secretKey' => null,
            'webhookSecret' => null,
        ]);
        $resolver->setAllowedTypes('publishableKey', ['null', 'string']);
        $resolver->setAllowedTypes('secretKey', ['null', 'string']);
        $resolver->setAllowedTypes('webhookSecret', ['null', 'string']);

        /** @var array{publishableKey: string|null, secretKey: string|null, webhookSecret: string|null} $resolved */
        $resolved = $resolver->resolve($stripe);

        return new StripeConfiguration(
            secretKey: $resolved['secretKey'],
            publishableKey: $resolved['publishableKey'],
            webhookSecret: $resolved['webhookSecret'],
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveSettings(
        array $settings,
        ?PageSettings $pageSettings,
    ): Settings {
        $this->assertKnownKeys($settings, self::SETTINGS_KEYS, 'settings');

        if (
            array_key_exists('priceSource', $settings)
            && $settings['priceSource'] !== null
            && is_string($settings['priceSource']) === false
        ) {
            throw new ConfigurationException('configuration.type_invalid', 'settings.priceSource');
        }

        if (
            isset($settings['priceSource'])
            && in_array($settings['priceSource'], [PriceSource::Kirby->value, PriceSource::Stripe->value], true) === false
        ) {
            throw new ConfigurationException('configuration.value_invalid', 'settings.priceSource');
        }

        $resolver = new OptionsResolver();
        $resolver->setDefault('priceSource', null);
        $resolver->setAllowedTypes('priceSource', ['null', 'string']);
        $resolver->setAllowedValues('priceSource', [
            null,
            PriceSource::Kirby->value,
            PriceSource::Stripe->value,
        ]);

        /** @var array{priceSource: string|null} $resolved */
        $resolved = $resolver->resolve($settings);

        // Null is deliberately "unset" and therefore cannot create a PHP lock.
        $phpValue = $resolved['priceSource'];
        $pageValue = $pageSettings?->priceSource();

        $setting = match (true) {
            $phpValue !== null => new Setting(
                settingValue: $phpValue,
                settingSource: SettingSource::Php,
                shadowed: $pageValue !== null,
                pageShadow: $pageValue,
            ),
            $pageValue !== null => new Setting(
                settingValue: $pageValue,
                settingSource: SettingSource::Page,
            ),
            default => new Setting(
                settingValue: PriceSource::Kirby->value,
                settingSource: SettingSource::InternalDefault,
            ),
        };

        return new Settings([
            'priceSource' => $setting,
        ]);
    }

    /**
     * @param array<mixed> $translations
     * @return array<string, array<string, string>>
     */
    private function resolveTranslations(array $translations): array
    {
        $resolved = [];
        $knownSuffixes = array_fill_keys(Catalogue::suffixes(), true);

        foreach ($translations as $locale => $overrides) {
            if (
                is_string($locale) === false
                || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $locale) !== 1
            ) {
                throw new ConfigurationException('configuration.translation_invalid', 'translations');
            }

            if (is_array($overrides) === false) {
                throw new ConfigurationException('configuration.translation_invalid', 'translations.' . $locale);
            }

            foreach ($overrides as $key => $value) {
                $path = 'translations.' . $locale;

                if (is_string($key) === false || trim($key) === '') {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                $path .= '.' . $key;

                if (isset($knownSuffixes[$key]) === false) {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                if (is_string($value) === false || trim($value) === '') {
                    throw new ConfigurationException('configuration.translation_invalid', $path);
                }

                $resolved[$locale][$key] = $value;
            }
        }

        ksort($resolved);

        foreach ($resolved as $locale => $overrides) {
            ksort($overrides);
            $resolved[$locale] = $overrides;
        }

        return $resolved;
    }

    private function validateCredentialCombination(StripeConfiguration $stripe): void
    {
        $serverMode = $stripe->serverMode();
        $publishableMode = $stripe->publishableMode();

        // Reject only a mismatch that can be proven from recognized prefixes;
        // Stripe remains authoritative for unknown or future key formats.
        if (
            $stripe->hasSecretKey()
            && $stripe->hasPublishableKey()
            && $serverMode !== CredentialMode::Unknown
            && $publishableMode !== CredentialMode::Unknown
            && $serverMode !== $publishableMode
        ) {
            throw new ConfigurationException(
                'configuration.credential_mode_mismatch',
                'stripe.publishableKey',
            );
        }
    }

    /**
     * @param array<mixed> $values
     * @param list<string> $knownKeys
     */
    private function assertKnownKeys(
        #[SensitiveParameter]
        array $values,
        array $knownKeys,
        ?string $parent = null,
    ): void {
        foreach (array_keys($values) as $key) {
            if (is_string($key) && in_array($key, $knownKeys, true)) {
                continue;
            }

            $path = $parent === null ? (string) $key : $parent . '.' . $key;

            throw new ConfigurationException('configuration.option_unknown', $path);
        }
    }
}
