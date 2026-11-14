<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Exception\Exception as KirbyException;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Exception\PermissionException;
use Kirby\Form\Fields;
use Kirby\Panel\Panel;
use Kirby\Panel\Ui\Buttons\ViewButton;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Exposes the protected Stripe Checkout Page through a permission-aware Panel area.
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
            // Kirby collects named buttons from every area, but renders this one only where a blueprint references it.
            'buttons' => [
                'page.stripeCheckoutOrderSync' => static fn(?string $orderId = null, ?string $stripeCheckoutSessionId = null): ?ViewButton => self::syncOrderButton(
                    kirby: $kirby,
                    orderId: $orderId,
                    stripeCheckoutSessionId: $stripeCheckoutSessionId,
                ),
                'page.stripeCheckoutOrderOpenInStripe' => static fn(?string $stripeDashboardUrl = null): ?ViewButton => self::openInStripeButton($stripeDashboardUrl),
            ],
            'dialogs' => [
                'order.sync' => [
                    'pattern' => 'stripe-checkout/orders/(:any)/sync',
                    'load' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::syncOrderDialog($kirby);
                    },
                    'submit' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::submitOrderSyncDialog($kirby, $orderId);
                    },
                ],
            ],
            'menu' => static function (array $areas = [], array $permissions = []) use ($kirby): bool {
                // Kirby calls menu callbacks with context while building the menu, then without arguments while normalizing the active area view.
                if ($permissions === []) {
                    return self::canRead($kirby);
                }

                $plugin = $permissions[PluginPermissions::CATEGORY] ?? [];

                if (is_array($plugin) === false) {
                    return false;
                }

                return ($plugin['settings.read'] ?? false) === true
                    || ($plugin['orders.read'] ?? false) === true
                    || ($plugin['diagnostics.read'] ?? false) === true;
            },
            'requests' => OperationalRequests::definition($kirby),
            'views' => [[
                'pattern' => 'stripe-checkout',
                'action' => function () use ($kirby): array {
                    return StripeCheckoutArea::view($kirby);
                },
            ]],
        ];
    }

    // Kirby rebinds area route closures to its Route object.
    // Public route handlers keep the actual work independent from that closure scope.
    /** @return array<string, mixed> */
    public static function view(App $kirby): array
    {
        self::requireRead($kirby);

        try {
            $page = (new StripeCheckoutPageStore($kirby))->initialize();
        } catch (ConfigurationException $error) {
            return self::errorView($error);
        }

        return self::pageView($kirby, $page);
    }

    /** @return array<string, mixed> */
    private static function pageView(App $kirby, StripeCheckoutPage $page): array
    {
        // Reuse Kirby's complete native Page view, changing only the navigation that would otherwise point outside the Stripe Checkout area.
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

        $settings = $report->configurationOrFail()->settings();

        /** @var array<string, \stdClass> $versions */
        $versions = $props['versions'];
        $fields = Fields::for($page);
        $latestContent = $page->version('latest')->content('current')->toArray();
        $changes = $page->version('changes');
        $changesContent = $changes->exists('current') ? $changes->content('current')->toArray() : null;

        // Project effective values only into the view.
        // Opening Settings must neither backfill content nor erase an intentionally blank pending edit.
        // Keep both versions aligned where no edit exists, otherwise defaults alone would make Kirby show an unsaved-change state on opening.
        foreach ($settings->all() as $name => $setting) {
            $field = $fields->get(strtolower($name));

            if ($field === null || $setting->value() === null) {
                continue;
            }

            $key = strtolower($name);
            $storedValue = $latestContent[$key] ?? null;
            $value = $setting->value();

            if ($name === 'customFields') {
                // Page values carry the stable Panel row IDs.
                // PHP definitions use their localized public projection and get view-only IDs.
                $value = $setting->isLocked()
                    ? array_map(
                        static fn(CustomField $customField): array => $customField->toArray(),
                        $settings->customFields(),
                    )
                    : $storedValue ?? [];
            }

            if ($name === 'shippingZones') {
                // Page-owned lists keep their persisted synchronization IDs.
                // The field gives PHP-locked public definitions view-only IDs because those identities are not part of project configuration.
                $value = $setting->isLocked()
                    ? array_map(
                        static fn(ShippingZone $shippingZone): array => $shippingZone->toArray(),
                        $settings->shippingZones(),
                    )
                    : $storedValue ?? [];
            }

            // This select uses yes/no option IDs; native toggles use booleans.
            if ($name === 'defaultRequiresShipping' && is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }

            try {
                $panelValue = $field->fill($value)->toFormValue();
            } catch (InvalidArgumentException) {
                // Effective-value projection is only a Panel convenience.
                // A projection mismatch must not hide the native recovery view.
                continue;
            }

            if (isset($versions['latest']) && ($setting->isLocked() || $storedValue === null || $storedValue === '')) {
                $versions['latest']->{$key} = $panelValue;
            }

            if (isset($versions['changes']) && ($setting->isLocked() || array_key_exists($key, $changesContent ?? []) === false)) {
                $versions['changes']->{$key} = $panelValue;
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
            || PluginPermissions::allows($kirby, 'orders.read')
            || PluginPermissions::allows($kirby, 'diagnostics.read');
    }

    private static function openInStripeButton(?string $stripeDashboardUrl): ?ViewButton
    {
        if ($stripeDashboardUrl === null) {
            return null;
        }

        return new ViewButton(
            icon: 'open',
            link: $stripeDashboardUrl,
            target: '_blank',
            text: 'programmatordev.stripe-checkout.orders.actions.openInStripe',
            rel: 'noopener noreferrer',
        );
    }

    private static function syncOrderButton(App $kirby, ?string $orderId, ?string $stripeCheckoutSessionId): ?ViewButton
    {
        if ($orderId === null) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'orders.read') === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'orders.reconcile') === false) {
            return null;
        }

        // This validated Order value controls presentation only.
        // The operational request reloads the Order and rechecks eligibility on submit.
        $disabled = $stripeCheckoutSessionId === null;

        return new ViewButton(
            dialog: $disabled ? null : 'stripe-checkout/orders/' . rawurlencode($orderId) . '/sync',
            disabled: $disabled,
            icon: 'refresh',
            text: 'programmatordev.stripe-checkout.orders.actions.sync',
            title: $disabled ? 'programmatordev.stripe-checkout.orders.actions.syncUnavailable' : null,
        );
    }

    /** @return array<string, mixed> */
    public static function syncOrderDialog(App $kirby): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.reconcile');

        return [
            'component' => 'k-text-dialog',
            'props' => [
                'size' => 'medium',
                'text' => self::translate('orders.actions.syncConfirm'),
                'submitButton' => [
                    'icon' => 'refresh',
                    'text' => self::translate('orders.actions.sync'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function submitOrderSyncDialog(App $kirby, string $orderId): array
    {
        // Keep reconciliation in its existing operational owner.
        // This adapter only maps the shared response envelope to Kirby's native dialog result.
        $response = OperationalRequests::syncOrder($kirby, $orderId);

        if ($response['status'] === 'success') {
            $message = ($response['result']['outcome'] ?? null) === 'updated'
                ? self::translate('orders.actions.syncUpdated')
                : self::translate('orders.actions.syncUnchanged');

            return [
                'event' => 'stripe-checkout.order.sync',
                'message' => $message,
            ];
        }

        $retryable = $response['error']['retryable'] ?? false;

        throw new KirbyException(
            message: self::translate($retryable
                ? 'orders.actions.syncRetryableFailure'
                : 'orders.actions.syncFailure'),
            httpCode: $response['code'],
        );
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
