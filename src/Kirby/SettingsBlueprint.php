<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Cms\App;
use Kirby\Data\Data;
use Kirby\Filesystem\F;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;

/**
 * Adapts the native Settings blueprint to the current user's permissions and PHP locks.
 *
 * @internal
 */
final class SettingsBlueprint
{
    /** @return array<string, mixed> */
    public static function load(App $kirby): array
    {
        $projectRoot = $kirby->root('blueprints');
        $projectFile = $projectRoot . '/pages/stripe-checkout-settings.yml';
        $blueprintFile = F::exists($projectFile, $projectRoot) === true
            ? $projectFile
            : dirname(__DIR__, 2) . '/blueprints/pages/stripe-checkout-settings.yml';

        /** @var array<string, mixed> $blueprint */
        $blueprint = Data::read($blueprintFile);

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
            $setting = $report->configurationOrFail()->settings()->setting('priceSource');

            if ($setting?->isLocked() === true) {
                self::applyPriceSourceLock($blueprint);
            }
        }

        return $blueprint;
    }

    /** @param array<string, mixed> $blueprint */
    private static function applyPriceSourceLock(array &$blueprint): void
    {
        $sections = $blueprint['sections'] ?? null;
        $settings = is_array($sections) ? ($sections['settings'] ?? null) : null;
        $fields = is_array($settings) ? ($settings['fields'] ?? null) : null;
        $field = is_array($fields) ? ($fields['priceSource'] ?? null) : null;

        if (is_array($sections) === false || is_array($settings) === false || is_array($fields) === false || is_array($field) === false) {
            return;
        }

        $field['disabled'] = true;
        $field['help'] = I18n::template(
            'programmatordev.stripe-checkout.settings.locked',
            ['path' => 'programmatordev.stripe-checkout.settings.priceSource'],
        );
        $fields['priceSource'] = $field;
        $settings['fields'] = $fields;
        $sections['settings'] = $settings;
        $blueprint['sections'] = $sections;
    }
}
