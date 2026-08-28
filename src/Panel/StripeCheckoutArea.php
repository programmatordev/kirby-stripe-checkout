<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Exception\PermissionException;
use Kirby\Panel\Panel;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPage;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Exposes the protected Settings Page through a permission-aware Panel area.
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
                    return self::canRead($kirby);
                }

                $plugin = $permissions[PluginPermissions::CATEGORY] ?? [];

                if (is_array($plugin) === false) {
                    return false;
                }

                return ($plugin['settings.read'] ?? false) === true
                    || ($plugin['diagnostics.read'] ?? false) === true;
            },
            'views' => [[
                'pattern' => 'stripe-checkout',
                'action' => function () use ($kirby): array {
                    return StripeCheckoutArea::view($kirby);
                },
            ]],
        ];
    }

    // Kirby rebinds route closures to its Route object. This public handler
    // keeps the actual work independent from that closure scope.
    /** @return array<string, mixed> */
    public static function view(App $kirby): array
    {
        self::requireRead($kirby);

        try {
            $page = (new SettingsPageStore($kirby))->initialize();
        } catch (ConfigurationException $error) {
            return self::errorView($error);
        }

        return self::pageView($kirby, $page);
    }

    /** @return array<string, mixed> */
    private static function pageView(App $kirby, SettingsPage $page): array
    {
        /** @var array<string, mixed> $view */
        $view = $page->panel()->view();
        $view['title'] = self::translate('area.label');
        unset($view['breadcrumb']);
        /** @var array<string, mixed> $props */
        $props = $view['props'];
        $props['title'] = self::translate('area.label');
        $props['prev'] = null;
        $props['next'] = null;
        /** @var list<array<string, mixed>> $tabs */
        $tabs = $props['tabs'];
        $props['tabs'] = self::areaTabLinks($tabs);

        if (isset($props['tab']) && is_array($props['tab'])) {
            /** @var array<string, mixed> $activeTab */
            $activeTab = $props['tab'];
            $props['tab'] = self::areaTabLink($activeTab);
        }

        /** @var array<string, bool> $permissions */
        $permissions = $props['permissions'];
        $permissions['update'] = PluginPermissions::allows($kirby, 'settings.read')
            && PluginPermissions::allows($kirby, 'settings.update');
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

    private static function canRead(App $kirby): bool
    {
        return PluginPermissions::allows($kirby, 'settings.read')
            || PluginPermissions::allows($kirby, 'diagnostics.read');
    }

    private static function requireRead(App $kirby): void
    {
        if (self::canRead($kirby) === false) {
            throw new PermissionException('No access');
        }
    }

    /**
     * @param list<array<string, mixed>> $tabs
     * @return list<array<string, mixed>>
     */
    private static function areaTabLinks(array $tabs): array
    {
        $linked = [];

        foreach ($tabs as $tab) {
            $linked[] = self::areaTabLink($tab);
        }

        return $linked;
    }

    /**
     * @param array<string, mixed> $tab
     * @return array<string, mixed>
     */
    private static function areaTabLink(array $tab): array
    {
        $name = is_string($tab['name'] ?? null) ? $tab['name'] : 'overview';
        $tab['link'] = Panel::url('stripe-checkout')
            . ($name === 'overview' ? '' : '?tab=' . rawurlencode($name));

        return $tab;
    }

    private static function failureMessage(ConfigurationException $error): string
    {
        $message = self::translate('error.' . $error->errorCode());

        return $error->path() === null ? $message : $message . ' (' . $error->path() . ')';
    }

    private static function translate(string $suffix): string
    {
        $translation = I18n::translate(Catalogue::PREFIX . $suffix);

        return is_string($translation) ? $translation : $suffix;
    }
}
