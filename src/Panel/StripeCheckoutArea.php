<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use DateTimeImmutable;
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
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingErrorCode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPageStore;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Lifecycle\Internal\HookDeliveryLedger;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderQueryException;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderStorageException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\RetentionPolicy;
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
    private const HTTP_CONFLICT = 409;

    private const TAB_DIAGNOSTICS = 'diagnostics';

    private const TAB_ORDERS = 'orders';

    private const TAB_OVERVIEW = 'overview';

    /** @return array<string, mixed> */
    public static function definition(App $kirby): array
    {
        return [
            'label' => self::translate('area.label'),
            'icon' => 'credit-card',
            // Kirby collects named buttons from every area, but renders this one only where a blueprint references it.
            'buttons' => [
                'page.stripeCheckoutRemoteDiagnostics' => static fn(): ?ViewButton => self::remoteDiagnosticsButton($kirby),
                'page.stripeCheckoutHousekeeping' => static fn(): ?ViewButton => self::housekeepingButton($kirby),
                'page.stripeCheckoutOrderDelete' => static fn(?string $orderId = null, bool $isManuallyDeletable = false): ?ViewButton => self::deleteOrderButton(
                    kirby: $kirby,
                    orderId: $orderId,
                    isManuallyDeletable: $isManuallyDeletable,
                ),
                'page.stripeCheckoutOrderRetryLifecycleDelivery' => static fn(?string $orderId = null, bool $hasRetryableLifecycleDelivery = false): ?ViewButton => self::retryLifecycleDeliveryButton(
                    kirby: $kirby,
                    orderId: $orderId,
                    hasRetryableLifecycleDelivery: $hasRetryableLifecycleDelivery,
                ),
                'page.stripeCheckoutOrderSync' => static fn(?string $orderId = null, ?string $stripeCheckoutSessionId = null): ?ViewButton => self::syncOrderButton(
                    kirby: $kirby,
                    orderId: $orderId,
                    stripeCheckoutSessionId: $stripeCheckoutSessionId,
                ),
                'page.stripeCheckoutOrderOpenInStripe' => static fn(?string $stripeDashboardUrl = null): ?ViewButton => self::openInStripeButton($stripeDashboardUrl),
            ],
            'dialogs' => [
                'diagnostics.run' => [
                    'pattern' => 'stripe-checkout/diagnostics/run',
                    'load' => function () use ($kirby): array {
                        return StripeCheckoutArea::remoteDiagnosticsDialog($kirby);
                    },
                    'submit' => function () use ($kirby): array {
                        return StripeCheckoutArea::submitRemoteDiagnosticsDialog($kirby);
                    },
                ],
                'housekeeping.run' => [
                    'pattern' => 'stripe-checkout/housekeeping/run',
                    'load' => function () use ($kirby): array {
                        return StripeCheckoutArea::housekeepingDialog($kirby);
                    },
                    'submit' => function () use ($kirby): array {
                        return StripeCheckoutArea::submitHousekeepingDialog($kirby);
                    },
                ],
                'order.delete' => [
                    'pattern' => 'stripe-checkout/orders/(:any)/delete',
                    'load' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::deleteOrderDialog($kirby, $orderId);
                    },
                    'submit' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::submitOrderDeletionDialog($kirby, $orderId);
                    },
                ],
                'order.sync' => [
                    'pattern' => 'stripe-checkout/orders/(:any)/sync',
                    'load' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::syncOrderDialog($kirby, $orderId);
                    },
                    'submit' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::submitOrderSyncDialog($kirby, $orderId);
                    },
                ],
                'order.delivery.retry' => [
                    'pattern' => 'stripe-checkout/orders/(:any)/deliveries/retry',
                    'load' => function (string $orderId) use ($kirby): array {
                        return StripeCheckoutArea::retryLifecycleDeliveryDialog($kirby, $orderId);
                    },
                    'submit' => function (string $orderId) use ($kirby): array {
                        $deliveryId = $kirby->request()->get('deliveryId');

                        return StripeCheckoutArea::submitLifecycleDeliveryRetryDialog(
                            kirby: $kirby,
                            orderId: $orderId,
                            deliveryId: is_string($deliveryId) ? $deliveryId : '',
                        );
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

    private static function remoteDiagnosticsButton(App $kirby): ?ViewButton
    {
        if (self::isDiagnosticsTab($kirby) === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'diagnostics.read') === false) {
            return null;
        }

        $disabled = self::isStripeServerConfigurationReady($kirby) === false;

        return new ViewButton(
            dialog: $disabled ? null : 'stripe-checkout/diagnostics/run',
            disabled: $disabled,
            icon: 'refresh',
            text: 'programmatordev.stripe-checkout.diagnostics.actions.runRemote',
            title: $disabled ? 'programmatordev.stripe-checkout.diagnostics.actions.runRemoteUnavailable' : null,
        );
    }

    private static function housekeepingButton(App $kirby): ?ViewButton
    {
        if (self::isDiagnosticsTab($kirby) === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'diagnostics.read') === false
            || PluginPermissions::allows($kirby, 'housekeeping.run') === false
        ) {
            return null;
        }

        $disabled = self::isStripeServerConfigurationReady($kirby) === false;

        return new ViewButton(
            dialog: $disabled ? null : 'stripe-checkout/housekeeping/run',
            disabled: $disabled,
            icon: 'refresh',
            text: 'programmatordev.stripe-checkout.housekeeping.actions.run',
            theme: 'notice',
            title: $disabled ? 'programmatordev.stripe-checkout.housekeeping.actions.runUnavailable' : null,
        );
    }

    private static function deleteOrderButton(App $kirby, ?string $orderId, bool $isManuallyDeletable): ?ViewButton
    {
        if ($orderId === null || $isManuallyDeletable === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'orders.read') === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'orders.delete') === false) {
            return null;
        }

        // This validated Order value controls presentation only.
        // The dialog reloads the Order and the operation rechecks eligibility under its write lock.
        return new ViewButton(
            dialog: 'stripe-checkout/orders/' . rawurlencode($orderId) . '/delete',
            icon: 'trash',
            text: 'programmatordev.stripe-checkout.orders.actions.delete',
            theme: 'negative',
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

    private static function retryLifecycleDeliveryButton(App $kirby, ?string $orderId, bool $hasRetryableLifecycleDelivery): ?ViewButton
    {
        if ($orderId === null || $hasRetryableLifecycleDelivery === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'orders.read') === false) {
            return null;
        }

        if (PluginPermissions::allows($kirby, 'lifecycle.retry') === false) {
            return null;
        }

        // This validated Order value controls presentation only.
        // The dialog reloads the Order and the operation rechecks eligibility under its write lock.
        return new ViewButton(
            dialog: 'stripe-checkout/orders/' . rawurlencode($orderId) . '/deliveries/retry',
            icon: 'refresh',
            text: 'programmatordev.stripe-checkout.orders.actions.retryLifecycleDelivery',
            theme: 'notice',
        );
    }

    /** @return array<string, mixed> */
    public static function remoteDiagnosticsDialog(App $kirby): array
    {
        PluginPermissions::require($kirby, 'diagnostics.read');

        if (self::isStripeServerConfigurationReady($kirby) === false) {
            throw new KirbyException(
                message: self::translate('diagnostics.actions.runRemoteUnavailable'),
                httpCode: self::HTTP_CONFLICT,
            );
        }

        return [
            'component' => 'k-text-dialog',
            'props' => [
                'size' => 'medium',
                'text' => self::translate('diagnostics.actions.runRemoteConfirm'),
                'submitButton' => [
                    'icon' => 'refresh',
                    'text' => self::translate('diagnostics.actions.runRemote'),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function submitRemoteDiagnosticsDialog(App $kirby): array
    {
        // The operational boundary owns permissions and the Stripe reads.
        $response = OperationalRequests::runDiagnostics($kirby);

        if ($response['status'] === 'success') {
            return [
                'message' => self::translate('diagnostics.actions.runRemoteSucceeded'),
            ];
        }

        throw new KirbyException(
            message: self::translate('diagnostics.actions.runRemoteFailed'),
            httpCode: $response['code'],
        );
    }

    /** @return array<string, mixed> */
    public static function housekeepingDialog(App $kirby): array
    {
        PluginPermissions::require($kirby, 'diagnostics.read');
        PluginPermissions::require($kirby, 'housekeeping.run');

        if (self::isStripeServerConfigurationReady($kirby) === false) {
            throw new KirbyException(
                message: self::translate('housekeeping.actions.runUnavailable'),
                httpCode: self::HTTP_CONFLICT,
            );
        }

        return [
            'component' => 'k-text-dialog',
            'props' => [
                'size' => 'medium',
                'text' => self::translate('housekeeping.actions.runConfirm'),
                'submitButton' => [
                    'icon' => 'refresh',
                    'text' => self::translate('housekeeping.actions.run'),
                ],
                'theme' => 'notice',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function submitHousekeepingDialog(App $kirby): array
    {
        // The same operational boundary and runner serve the Panel and CLI.
        $response = OperationalRequests::runHousekeeping($kirby);

        if ($response['status'] === 'success') {
            return [
                'message' => self::translate('housekeeping.actions.runSucceeded'),
            ];
        }

        if ($response['status'] === 'partial_failure') {
            return [
                'message' => self::translate('housekeeping.actions.runPartialFailure'),
            ];
        }

        $error = $response['error'];

        throw new KirbyException(
            message: self::translate($error !== null && $error['code'] === HousekeepingErrorCode::LOCKED
                ? 'housekeeping.actions.runLocked'
                : 'housekeeping.actions.runFailed'),
            httpCode: $response['code'],
        );
    }

    /** @return array<string, mixed> */
    public static function syncOrderDialog(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.reconcile');

        if (self::hasStripeCheckoutSession($kirby, $orderId) === false) {
            throw new KirbyException(
                message: self::translate('orders.actions.syncUnavailable'),
                httpCode: self::HTTP_CONFLICT,
            );
        }

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

    /** @return array<string, mixed> */
    public static function deleteOrderDialog(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'orders.delete');

        if (self::isOrderManuallyDeletable($kirby, $orderId) === false) {
            throw new KirbyException(
                message: self::translate('orders.actions.deleteUnavailable'),
                httpCode: self::HTTP_CONFLICT,
            );
        }

        return [
            'component' => 'k-remove-dialog',
            'props' => [
                'text' => self::translate('orders.actions.deleteConfirm'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function submitOrderDeletionDialog(App $kirby, string $orderId): array
    {
        // Keep eligibility and deletion in the existing locked operation.
        // This adapter only maps its shared response envelope to Kirby's native dialog result.
        $response = OperationalRequests::deleteOrder($kirby, $orderId);

        if ($response['status'] === 'success') {
            return [
                'event' => 'page.delete',
                'message' => self::translate('orders.actions.deleteSucceeded'),
                'redirect' => Panel::url('stripe-checkout') . '?tab=' . self::TAB_ORDERS,
            ];
        }

        $retryable = $response['error']['retryable'] ?? false;

        throw new KirbyException(
            message: self::translate($retryable
                ? 'orders.actions.deleteRetryableFailure'
                : 'orders.actions.deleteUnavailable'),
            httpCode: $response['code'],
        );
    }

    /** @return array<string, mixed> */
    public static function retryLifecycleDeliveryDialog(App $kirby, string $orderId): array
    {
        PluginPermissions::require($kirby, 'orders.read');
        PluginPermissions::require($kirby, 'lifecycle.retry');
        $options = self::retryableLifecycleDeliveryOptions($kirby, $orderId);

        if ($options === []) {
            throw new KirbyException(
                message: self::translate('orders.actions.retryLifecycleDeliveryUnavailable'),
                httpCode: self::HTTP_CONFLICT,
            );
        }

        // Kirby's native Structure field has no supported per-row action extension.
        // A native form dialog keeps the target explicit without custom Panel JavaScript.
        return [
            'component' => 'k-form-dialog',
            'props' => [
                'fields' => [
                    'warning' => [
                        'text' => self::translate('orders.actions.retryLifecycleDeliveryConfirm'),
                        'theme' => 'notice',
                        'type' => 'info',
                    ],
                    'deliveryId' => [
                        'label' => self::translate('orders.actions.retryLifecycleDeliveryTarget'),
                        'type' => 'select',
                        'required' => true,
                        'options' => $options,
                        'placeholder' => self::translate('orders.actions.retryLifecycleDeliverySelect'),
                    ],
                ],
                'size' => 'medium',
                'submitButton' => [
                    'icon' => 'refresh',
                    'text' => self::translate('orders.actions.retryLifecycleDelivery'),
                ],
                'theme' => 'notice',
                'value' => [
                    'deliveryId' => count($options) === 1 ? $options[0]['value'] : null,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function submitLifecycleDeliveryRetryDialog(App $kirby, string $orderId, string $deliveryId): array
    {
        // Keep retry admission, delivery and outcome persistence in the existing operation.
        // This adapter only maps its shared response envelope to Kirby's native dialog result.
        $response = OperationalRequests::retryFailedDelivery($kirby, $orderId, $deliveryId);

        if ($response['status'] === 'success') {
            return [
                'message' => self::translate('orders.actions.retryLifecycleDeliverySucceeded'),
            ];
        }

        $retryable = $response['error']['retryable'] ?? false;

        throw new KirbyException(
            message: self::translate($retryable
                ? 'orders.actions.retryLifecycleDeliveryRetryableFailure'
                : 'orders.actions.retryLifecycleDeliveryFailure'),
            httpCode: $response['code'],
        );
    }

    /** @return list<array{value: string, text: string}> */
    private static function retryableLifecycleDeliveryOptions(App $kirby, string $orderId): array
    {
        try {
            $pageUuid = OrderData::pageUuid($orderId);
            $store = new OrderPageStore($kirby);
            $order = $store->order($pageUuid);

            if ($order === null) {
                return [];
            }

            $data = $store->data($order);
        } catch (OrderDataException|OrderQueryException|OrderStorageException) {
            return [];
        }

        /** @var list<array<string, mixed>> $lifecycleDeliveries */
        $lifecycleDeliveries = $data['lifecycleDeliveries'];
        $attemptedAt = new DateTimeImmutable();
        $options = [];

        foreach ($lifecycleDeliveries as $lifecycleDelivery) {
            if (HookDeliveryLedger::isFailedRetryEligible($lifecycleDelivery, $attemptedAt) === false) {
                continue;
            }

            /** @var array{deliveryId: string, type: string} $event */
            $event = $lifecycleDelivery['event'];
            $options[] = [
                'value' => $event['deliveryId'],
                'text' => $event['type'] . ' — ' . $event['deliveryId'],
            ];
        }

        return $options;
    }

    private static function hasStripeCheckoutSession(App $kirby, string $orderId): bool
    {
        try {
            $pageUuid = OrderData::pageUuid($orderId);
            $store = new OrderPageStore($kirby);
            $order = $store->order($pageUuid);

            if ($order === null) {
                return false;
            }

            return isset($store->data($order)['stripeCheckoutSessionId']);
        } catch (OrderDataException|OrderQueryException|OrderStorageException) {
            return false;
        }
    }

    private static function isDiagnosticsTab(App $kirby): bool
    {
        // Kirby exposes Page buttons at view level, so tab-specific actions must resolve the active tab here.
        $tab = $kirby->request()->get('tab');

        if (is_string($tab)) {
            return $tab === self::TAB_DIAGNOSTICS;
        }

        // Diagnostics is selected implicitly only when it is the first accessible tab.
        return PluginPermissions::allows($kirby, 'diagnostics.read')
            && PluginPermissions::allows($kirby, 'settings.read') === false
            && PluginPermissions::allows($kirby, 'orders.read') === false;
    }

    private static function isStripeServerConfigurationReady(App $kirby): bool
    {
        $report = (new RuntimeFactory($kirby))->configurationReport();

        return $report->isValid()
            && $report->configurationOrFail()->stripe()->hasSecretKey();
    }

    private static function isOrderManuallyDeletable(App $kirby, string $orderId): bool
    {
        try {
            $pageUuid = OrderData::pageUuid($orderId);
            $store = new OrderPageStore($kirby);
            $order = $store->order($pageUuid);

            if ($order === null) {
                return false;
            }

            return RetentionPolicy::isManuallyEligible(
                data: $store->data($order),
                now: new DateTimeImmutable(),
            );
        } catch (OrderDataException|OrderQueryException|OrderStorageException) {
            // Never expose a destructive confirmation when the current Order cannot be safely revalidated.
            return false;
        }
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
        $name = is_string($tab['name'] ?? null) ? $tab['name'] : self::TAB_OVERVIEW;
        $tab['link'] = Panel::url('stripe-checkout')
            . ($name === self::TAB_OVERVIEW ? '' : '?tab=' . rawurlencode($name));

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
