<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Collator;
use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\Defaults;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Panel\DiagnosticsSections;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Throwable;

/**
 * Adapts the plugin-owned Settings blueprint to permissions and PHP locks.
 *
 * @internal
 */
final class SettingsBlueprint
{
    /** @return array<string, mixed> */
    public static function load(App $kirby): array
    {
        /** @var array<string, mixed> $blueprint */
        $blueprint = Data::read(
            dirname(__DIR__, 2) . '/blueprints/pages/stripe-checkout.yml',
        );
        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = $blueprint['tabs'];
        /** @var array<string, mixed> $settingsTab */
        $settingsTab = $tabs['settings'];
        /** @var array<string, array<string, mixed>> $settingsSections */
        $settingsSections = $settingsTab['sections'];
        /** @var array<string, mixed> $settingsSection */
        $settingsSection = $settingsSections['settings'];
        /** @var array<string, array<string, mixed>> $settingsFields */
        $settingsFields = $settingsSection['fields'];
        // The provider registry and active Panel locale make these options
        // runtime data; the YAML blueprint supplies only their static field.
        $settingsFields['currency']['options'] = self::currencyOptions();
        $settingsFields['shippingZones']['countryOptions'] = self::countryOptions();

        // Use the same defaults for native Page creation and runtime fallbacks.
        foreach (Defaults::SETTINGS as $name => $default) {
            if ($default !== null && is_array($settingsFields[$name] ?? null)) {
                $settingsFields[$name]['default'] = $default;
            }
        }

        $settingsSection['fields'] = $settingsFields;
        $settingsSections['settings'] = $settingsSection;
        $settingsTab['sections'] = $settingsSections;
        $tabs['settings'] = $settingsTab;
        $blueprint['tabs'] = $tabs;

        $canReadSettings = PluginPermissions::allows($kirby, 'settings.read');
        $canReadDiagnostics = PluginPermissions::allows($kirby, 'diagnostics.read');
        $canReadArea = $canReadSettings || $canReadDiagnostics;
        $blueprintOptions = $blueprint['options'] ?? [];
        $blueprintOptions = is_array($blueprintOptions) ? $blueprintOptions : [];
        $blueprintOptions['access'] = $canReadArea;
        $blueprintOptions['list'] = false;
        $blueprintOptions['read'] = $canReadArea;
        $blueprintOptions['update'] = $canReadSettings
            && PluginPermissions::allows($kirby, 'settings.update');
        $blueprint['options'] = $blueprintOptions;

        /** @var array<string, array<string, mixed>> $tabs */
        $tabs = $blueprint['tabs'];

        if ($canReadSettings === false) {
            unset($tabs['settings']);
        }

        if ($canReadDiagnostics === true) {
            $tabs['diagnostics']['sections'] = DiagnosticsSections::build($kirby);
        } else {
            unset($tabs['diagnostics']);
        }

        $blueprint['tabs'] = $tabs;

        /** @var array<string, mixed> $options */
        $options = $kirby->options();
        $resolver = new ConfigurationResolver(
            languageCode: $kirby->language()?->code(),
        );
        $report = $resolver->resolve($options);

        try {
            $lockedSettings = $resolver->lockedSettingNames($options);
        } catch (ConfigurationException) {
            $lockedSettings = [];
        }

        try {
            $customShippingResolver = $resolver->shippingResolver($options) !== null;
        } catch (ConfigurationException) {
            $customShippingResolver = false;
        }

        $resolvedSettings = $report->isValid()
            ? $report->configurationOrFail()->settings()
            : null;

        foreach ($lockedSettings as $name) {
            $lockedValue = null;

            if ($name === 'customFields' && $resolvedSettings !== null) {
                $lockedValue = array_map(
                    static fn(CustomField $customField): array => $customField->toArray(),
                    $resolvedSettings->customFields(),
                );
            }

            if ($name === 'shippingZones' && $resolvedSettings !== null) {
                $lockedValue = array_map(
                    static fn(ShippingZone $zone): array => $zone->toArray(),
                    $resolvedSettings->shippingZones(),
                );
            }

            /** @var array<string, mixed> $blueprint */
            $blueprint = self::applyLock(
                blueprint: $blueprint,
                fieldName: $name,
                lockedValue: $lockedValue,
            );
        }

        if ($customShippingResolver) {
            $shippingZonesLocked = in_array('shippingZones', $lockedSettings, true);
            $help = $shippingZonesLocked
                ? I18n::template(
                    'programmatordev.stripe-checkout.settings.shippingZones.resolverInactiveLocked',
                    ['path' => 'programmatordev.stripe-checkout.settings.shippingZones'],
                )
                : I18n::translate(
                    'programmatordev.stripe-checkout.settings.shippingZones.resolverInactive',
                );
            $blueprint = self::applyFieldProperties(
                blueprint: $blueprint,
                fieldName: 'shippingZones',
                properties: [
                    'disabled' => true,
                    'help' => $help,
                ],
            );
        }

        /** @var array<string, mixed> $blueprint */
        return $blueprint;
    }

    /** @return array<string, string> */
    private static function currencyOptions(): array
    {
        $options = [];

        foreach ((new StripeCurrencyRegistry())->codes() as $currency) {
            try {
                $name = Currencies::getName($currency, I18n::locale());
            } catch (Throwable) {
                $name = $currency;
            }

            $options[$currency] = $currency . ' — ' . $name;
        }

        return $options;
    }

    /** @return array<string, string> */
    private static function countryOptions(): array
    {
        $options = [];

        foreach ((new StripeShippingCountryRegistry())->codes() as $country) {
            try {
                $name = Countries::getName($country, I18n::locale());
            } catch (Throwable) {
                $name = $country;
            }

            if ($name === $country) {
                $translatedName = I18n::translate(
                    'programmatordev.stripe-checkout.settings.shippingZones.countries.' . strtolower($country),
                    $country,
                );
                $name = is_string($translatedName) ? $translatedName : $country;
            }

            $options[$country] = $name;
        }

        (new Collator(I18n::locale()))->asort($options, Collator::SORT_STRING);

        return $options;
    }

    /**
     * @param array<mixed, mixed> $blueprint
     * @return array<mixed, mixed>
     */
    private static function applyLock(
        array $blueprint,
        string $fieldName,
        mixed $lockedValue = null,
    ): array {
        $properties = [
            'disabled' => true,
            'help' => I18n::template(
                'programmatordev.stripe-checkout.settings.locked',
                ['path' => 'programmatordev.stripe-checkout.settings.' . $fieldName],
            ),
        ];

        if ($lockedValue !== null) {
            $properties['lockedValue'] = $lockedValue;
        }

        return self::applyFieldProperties($blueprint, $fieldName, $properties);
    }

    /**
     * @param array<mixed, mixed> $blueprint
     * @param array<string, mixed> $properties
     * @return array<mixed, mixed>
     */
    private static function applyFieldProperties(
        array $blueprint,
        string $fieldName,
        array $properties,
    ): array {
        foreach ($blueprint as $key => $value) {
            if (is_array($value) === false) {
                continue;
            }

            if ($key === 'fields' && is_array($value[$fieldName] ?? null)) {
                $value[$fieldName] = [...$value[$fieldName], ...$properties];
                $blueprint[$key] = $value;

                continue;
            }

            $blueprint[$key] = self::applyFieldProperties($value, $fieldName, $properties);
        }

        return $blueprint;
    }
}
