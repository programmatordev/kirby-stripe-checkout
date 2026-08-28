<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Panel\Panel;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPage;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Defines the minimal permission-aware Panel area from Kirby-native primitives.
 *
 * @internal
 */
final class StripeCheckoutArea
{
    /** @return array<string, mixed> */
    public static function definition(App $kirby): array
    {
        return [
            'label' => self::translate('area.label'),
            'icon' => 'credit-card',
            'menu' => static function (array $areas = [], array $permissions = []) use ($kirby): bool {
                // Kirby calls menu callbacks with context while building the menu,
                // then without arguments while normalizing the active area view.
                if ($permissions === []) {
                    return PluginPermissions::allows($kirby, 'settings.read')
                        || PluginPermissions::allows($kirby, 'diagnostics.read');
                }

                $plugin = $permissions[PluginPermissions::CATEGORY] ?? [];

                if (is_array($plugin) === false) {
                    return false;
                }

                return ($plugin['settings.read'] ?? false) === true
                    || ($plugin['diagnostics.read'] ?? false) === true;
            },
            'views' => [
                [
                    'pattern' => 'stripe-checkout',
                    'action' => function () use ($kirby): array {
                        return StripeCheckoutArea::overview($kirby);
                    },
                ],
                [
                    'pattern' => 'stripe-checkout/settings',
                    'action' => function () use ($kirby): array {
                        return StripeCheckoutArea::settings($kirby);
                    },
                ],
                [
                    'pattern' => 'stripe-checkout/diagnostics',
                    'action' => function () use ($kirby): array {
                        return StripeCheckoutArea::diagnostics($kirby);
                    },
                ],
            ],
        ];
    }

    // Kirby rebinds route closures to its Route object. These public handlers
    // keep the actual work in this internal class without depending on closure scope.
    /** @return array<string, mixed> */
    public static function overview(App $kirby): array
    {
        self::requireAreaRead($kirby);
        $items = [];

        if (PluginPermissions::allows($kirby, 'settings.read')) {
            $items[] = [
                'text' => self::translate('overview.settings'),
                'info' => self::translate('overview.settings.info'),
                'icon' => 'settings',
                'link' => Panel::url('stripe-checkout/settings'),
            ];
        }

        if (PluginPermissions::allows($kirby, 'diagnostics.read')) {
            $items[] = [
                'text' => self::translate('overview.diagnostics'),
                'info' => self::translate('overview.diagnostics.info'),
                'icon' => 'info',
                'link' => Panel::url('stripe-checkout/diagnostics'),
            ];
        }

        return [
            'component' => 'k-stripe-checkout-overview-view',
            'title' => self::translate('overview.title'),
            'props' => [
                'description' => self::translate('overview.description'),
                'items' => $items,
                'tabs' => self::tabs($kirby),
                'title' => self::translate('overview.title'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(App $kirby): array
    {
        PluginPermissions::require($kirby, 'settings.read');

        try {
            $page = (new SettingsPageStore($kirby))->initialize();
        } catch (ConfigurationException $error) {
            return self::errorView($error);
        }

        return self::settingsPageView($kirby, $page);
    }

    /** @return array<string, mixed> */
    private static function settingsPageView(App $kirby, SettingsPage $page): array
    {
        /** @var array<string, mixed> $view */
        $view = $page->panel()->view();
        $view['component'] = 'k-stripe-checkout-settings-view';
        $view['title'] = self::translate('tabs.settings');
        unset($view['breadcrumb']);
        /** @var array<string, mixed> $props */
        $props = $view['props'];
        $props['areaTabs'] = self::tabs($kirby);
        $props['title'] = self::translate('tabs.settings');
        /** @var array<string, bool> $permissions */
        $permissions = $props['permissions'];
        $permissions['update'] = PluginPermissions::allows($kirby, 'settings.update');
        $props['permissions'] = $permissions;
        $view['props'] = $props;
        $report = (new RuntimeFactory($kirby))->configurationReport();

        if ($report->isValid() === false) {
            return $view;
        }

        /** @var array<string, \stdClass> $versions */
        $versions = $props['versions'];

        foreach ($report->configurationOrFail()->settings()->all() as $name => $setting) {
            if ($setting->isLocked() === false) {
                continue;
            }

            foreach (['latest', 'changes'] as $version) {
                if (isset($versions[$version])) {
                    $versions[$version]->{strtolower($name)} = $setting->value();
                }
            }
        }

        $props['versions'] = $versions;
        $view['props'] = $props;

        return $view;
    }

    /** @return array<string, mixed> */
    public static function diagnostics(App $kirby): array
    {
        PluginPermissions::require($kirby, 'diagnostics.read');
        $report = (new LocalDiagnostics($kirby))->report();
        $checks = [];

        foreach ($report['checks'] as $check) {
            $values = $check['values'];

            if (isset($values['mode'])) {
                $values['mode'] = self::translate('credentialMode.' . $values['mode']);
            }

            if (isset($values['code'])) {
                $values['code'] = self::translate('error.' . $values['code']);
            }

            $checks[] = [
                'text' => self::translate('diagnostics.' . $check['id']),
                'info' => self::template('diagnostics.' . $check['message'], $values),
                'icon' => self::statusIcon($check['status']),
                'theme' => self::statusTheme($check['status']),
            ];
        }

        return [
            'component' => 'k-stripe-checkout-diagnostics-view',
            'title' => self::translate('diagnostics.title'),
            'props' => [
                'checks' => $checks,
                'description' => self::translate('diagnostics.description'),
                'status' => self::translate('diagnostics.status.' . $report['status']),
                'statusTheme' => self::statusTheme($report['status']),
                'tabs' => self::tabs($kirby),
                'title' => self::translate('diagnostics.title'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function errorView(ConfigurationException $error): array
    {
        return [
            'component' => 'k-error-view',
            'title' => self::translate('area.label'),
            'props' => [
                'error' => self::failureMessage($error),
                'layout' => 'inside',
            ],
        ];
    }

    private static function requireAreaRead(App $kirby): void
    {
        if (
            PluginPermissions::allows($kirby, 'settings.read') === false
            && PluginPermissions::allows($kirby, 'diagnostics.read') === false
        ) {
            throw new \Kirby\Exception\PermissionException('No access');
        }
    }

    /** @return list<array{name: string, label: string, link: string}> */
    private static function tabs(App $kirby): array
    {
        $tabs = [[
            'name' => 'overview',
            'label' => self::translate('tabs.overview'),
            'link' => Panel::url('stripe-checkout'),
        ]];

        if (PluginPermissions::allows($kirby, 'settings.read')) {
            $tabs[] = [
                'name' => 'settings',
                'label' => self::translate('tabs.settings'),
                'link' => Panel::url('stripe-checkout/settings'),
            ];
        }

        if (PluginPermissions::allows($kirby, 'diagnostics.read')) {
            $tabs[] = [
                'name' => 'diagnostics',
                'label' => self::translate('tabs.diagnostics'),
                'link' => Panel::url('stripe-checkout/diagnostics'),
            ];
        }

        return $tabs;
    }

    private static function failureMessage(ConfigurationException $error): string
    {
        $message = self::translate('error.' . $error->errorCode());

        return $error->path() === null ? $message : $message . ' (' . $error->path() . ')';
    }

    /** @param array<string, string> $values */
    private static function template(string $suffix, array $values): string
    {
        return I18n::template(Catalogue::PREFIX . $suffix, $values);
    }

    private static function translate(string $suffix): string
    {
        $translation = I18n::translate(Catalogue::PREFIX . $suffix);

        return is_string($translation) ? $translation : $suffix;
    }

    private static function statusIcon(string $status): string
    {
        return match ($status) {
            LocalDiagnostics::PASS => 'check',
            LocalDiagnostics::FAIL => 'alert',
            LocalDiagnostics::WARNING => 'alert',
            default => 'question',
        };
    }

    private static function statusTheme(string $status): string
    {
        return match ($status) {
            LocalDiagnostics::PASS => 'positive',
            LocalDiagnostics::FAIL => 'negative',
            LocalDiagnostics::WARNING => 'warning',
            default => 'info',
        };
    }
}
