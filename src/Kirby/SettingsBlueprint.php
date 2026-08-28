<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;

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
            dirname(__DIR__, 2) . '/blueprints/pages/stripe-checkout-settings.yml',
        );

        $blueprintOptions = $blueprint['options'] ?? [];
        $blueprintOptions = is_array($blueprintOptions) ? $blueprintOptions : [];
        $blueprintOptions['access'] = PluginPermissions::allows($kirby, 'settings.read');
        $blueprintOptions['list'] = false;
        $blueprintOptions['read'] = PluginPermissions::allows($kirby, 'settings.read');
        $blueprintOptions['update'] = PluginPermissions::allows($kirby, 'settings.update');
        $blueprint['options'] = $blueprintOptions;

        /** @var array<string, mixed> $options */
        $options = $kirby->options();
        $report = (new ConfigurationResolver())->resolve($options);

        if ($report->isValid() === true) {
            foreach ($report->configurationOrFail()->settings()->all() as $name => $setting) {
                if ($setting->isLocked() === true) {
                    /** @var array<string, mixed> $blueprint */
                    $blueprint = self::applyLock($blueprint, $name);
                }
            }
        }

        return $blueprint;
    }

    /**
     * @param array<mixed, mixed> $blueprint
     * @return array<mixed, mixed>
     */
    private static function applyLock(array $blueprint, string $fieldName): array
    {
        foreach ($blueprint as $key => $value) {
            if (is_array($value) === false) {
                continue;
            }

            if ($key === 'fields' && is_array($value[$fieldName] ?? null)) {
                $value[$fieldName]['disabled'] = true;
                $value[$fieldName]['help'] = I18n::template(
                    'programmatordev.stripe-checkout.settings.locked',
                    ['path' => 'programmatordev.stripe-checkout.settings.' . $fieldName],
                );
                $blueprint[$key] = $value;

                continue;
            }

            $blueprint[$key] = self::applyLock($value, $fieldName);
        }

        return $blueprint;
    }
}
