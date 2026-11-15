<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Panel\Panel;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticCheck;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticReport;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Kirby\PluginPermissions;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * @internal Presents diagnostic reports through Kirby's native section components.
 *
 * @phpstan-type DiagnosticAction array{dialog: ?string, disabled: bool, icon: string, text: string, theme?: string, title: ?string}
 * @phpstan-type DiagnosticItemButton array{dialog?: string, endpoint?: string, icon: string, link?: string, rel?: string, successMessage?: string, target?: string, title: string, variant: string}
 * @phpstan-type DiagnosticItem array{buttons: list<DiagnosticItemButton>, id: string, image: array{back: string, color: string, icon: string}, info: string, text: string, theme: string}
 * @phpstan-type DiagnosticSectionData array{action: ?DiagnosticAction, headline: string, items: list<DiagnosticItem>, name: string}
 * @phpstan-type DiagnosticSummaryReport array{label: string, value: string, info: ?string, icon: string, theme: string}
 * @phpstan-type DiagnosticResponse array{summary: array{headline: string, reports: list<DiagnosticSummaryReport>}, sections: list<DiagnosticSectionData>}
 */
final class DiagnosticsSection
{
    private const STRIPE_API_KEYS_URL = 'https://dashboard.stripe.com/apikeys';
    private const STRIPE_WEBHOOKS_URL = 'https://dashboard.stripe.com/webhooks';

    private const CHECKOUT_SETUP_CHECKS = [
        'configuration',
        'currency',
        'hubPage',
        'intl',
        'kirby',
        'orderIdentity',
        'orders',
        'php',
        'prices',
        'publishableKey',
        'secretKey',
        'storageWriteAccess',
        'stripePhp',
        'taxCodes',
    ];

    private const SYSTEM_CHECKS = [
        'hubPage',
        'orderIdentity',
        'orders',
        'prices',
        'storageWriteAccess',
        'taxCodes',
        'webhookRoute',
    ];

    private const ENVIRONMENT_CHECKS = [
        'intl',
        'kirby',
        'php',
        'stripePhp',
    ];

    private const OPERATION_CHECKS = [
        'events',
        'housekeepingRun',
        'lifecycle',
        'lifecycleDeletion',
    ];

    /** @return array<string, mixed> */
    public static function definition(): array
    {
        return [
            // Kirby rebinds section callbacks, so resolve the active App when the endpoint executes.
            'toArray' => fn(): array => DiagnosticsSection::response(App::instance()),
        ];
    }

    /** @return DiagnosticResponse */
    public static function response(App $kirby): array
    {
        $report = (new LocalDiagnostics($kirby))->report();
        $remoteReport = (new RuntimeFactory($kirby))->diagnosticReportStore()->read();
        $attentionItems = [];
        $groups = [
            'operations' => [],
            'system' => [],
            'environment' => [],
        ];

        foreach ($report->checks() as $check) {
            $item = self::checkItem($kirby, $check);

            if (self::needsAttention($check)) {
                $attentionItems[] = $item;

                continue;
            }

            if (self::displayHealthyCheck($check) === false) {
                continue;
            }

            $group = self::localGroup($check);

            if ($group === 'operations' && $check->code() === 'housekeepingRun') {
                array_unshift($groups[$group], $item);

                continue;
            }

            $groups[$group][] = $item;
        }

        $stripeItems = [self::remoteSummaryItem($kirby, $remoteReport)];

        if ($remoteReport !== null) {
            foreach ($remoteReport->checks() as $check) {
                $item = self::checkItem($kirby, $check, 'remote-');

                if (self::needsAttention($check)) {
                    $attentionItems[] = $item;

                    continue;
                }

                $stripeItems[] = $item;
            }
        }

        $sections = [];

        if ($attentionItems !== []) {
            $sections[] = self::section('attention', $attentionItems);
        }

        $sections[] = self::section(
            name: 'stripe',
            items: $stripeItems,
            action: self::remoteDiagnosticsAction($kirby),
        );
        $sections[] = self::section(
            name: 'operations',
            items: $groups['operations'],
            action: self::housekeepingAction($kirby),
        );
        $sections[] = self::section('system', $groups['system']);
        $sections[] = self::section('environment', $groups['environment']);

        return [
            'summary' => [
                'headline' => self::translate('diagnostics.summary.label'),
                'reports' => self::summaryReports($report, $remoteReport),
            ],
            'sections' => $sections,
        ];
    }

    /** @return DiagnosticItem */
    private static function checkItem(App $kirby, DiagnosticCheck $check, string $idPrefix = ''): array
    {
        $context = $check->context();

        foreach (['mode', 'configuredMode', 'providerMode'] as $contextKey) {
            if (isset($context[$contextKey])) {
                $context[$contextKey] = self::translate('credentialMode.' . $context[$contextKey]);
            }
        }

        if (isset($context['code'])) {
            $translation = I18n::translate(Catalogue::PREFIX . 'error.' . $context['code']);

            if (is_string($translation)) {
                $context['code'] = $translation;
            }
        }

        $item = self::item(
            kirby: $kirby,
            id: $idPrefix . $check->code(),
            label: self::translate('diagnostics.' . $check->code()),
            message: self::template('diagnostics.' . $check->messageKey(), $context),
            status: $check->status(),
            buttons: self::checkButtons($kirby, $check),
        );

        if ($check->code() === 'housekeepingRun' && $check->status() === DiagnosticStatus::Pass) {
            // Successful run rows are metadata, matching the latest Stripe check.
            $item['image']['icon'] = 'clock';
            $item['theme'] = 'info';
        }

        return $item;
    }

    /** @return DiagnosticAction|null */
    private static function housekeepingAction(App $kirby): ?array
    {
        if (PluginPermissions::allows($kirby, 'housekeeping.run') === false) {
            return null;
        }

        $disabled = self::isStripeServerConfigurationReady($kirby) === false;

        return [
            'dialog' => $disabled ? null : 'stripe-checkout/housekeeping/run',
            'disabled' => $disabled,
            'icon' => 'refresh',
            'text' => self::translate('housekeeping.actions.run'),
            'title' => $disabled ? self::translate('housekeeping.actions.runUnavailable') : null,
        ];
    }

    /**
     * @param list<DiagnosticItemButton> $buttons
     * @return DiagnosticItem
     */
    private static function item(
        App $kirby,
        string $id,
        string $label,
        string $message,
        DiagnosticStatus $status,
        array $buttons = [],
    ): array {
        return [
            'buttons' => $buttons,
            'id' => $id,
            'image' => [
                'back' => 'light-dark(var(--theme-color-200), var(--theme-color-900))',
                'color' => 'var(--theme-color-icon)',
                'icon' => self::statusIcon($status),
            ],
            'info' => $kirby->kirbytext($message, ['markdown' => ['inline' => true]]),
            'text' => $label,
            'theme' => self::itemTheme($status),
        ];
    }

    private static function itemTheme(DiagnosticStatus $status): string
    {
        return match ($status) {
            DiagnosticStatus::Pass => 'passive',
            DiagnosticStatus::Fail => 'negative',
            DiagnosticStatus::Warning => 'notice',
            default => 'info',
        };
    }

    private static function isStripeServerConfigurationReady(App $kirby): bool
    {
        $report = (new RuntimeFactory($kirby))->configurationReport();

        return $report->isValid()
            && $report->configurationOrFail()->stripe()->hasSecretKey();
    }

    private static function localGroup(DiagnosticCheck $check): string
    {
        return match (true) {
            in_array($check->code(), self::SYSTEM_CHECKS, true) => 'system',
            in_array($check->code(), self::ENVIRONMENT_CHECKS, true) => 'environment',
            default => 'operations',
        };
    }

    private static function displayHealthyCheck(DiagnosticCheck $check): bool
    {
        if (in_array($check->messageKey(), ['prices.empty', 'taxCodes.empty'], true)) {
            return false;
        }

        return in_array($check->code(), [
            ...self::SYSTEM_CHECKS,
            ...self::ENVIRONMENT_CHECKS,
            ...self::OPERATION_CHECKS,
        ], true);
    }

    private static function needsAttention(DiagnosticCheck $check): bool
    {
        return $check->status() === DiagnosticStatus::Fail
            || $check->status() === DiagnosticStatus::Warning;
    }

    /** @return DiagnosticAction */
    private static function remoteDiagnosticsAction(App $kirby): array
    {
        $disabled = self::isStripeServerConfigurationReady($kirby) === false;

        return [
            'dialog' => $disabled ? null : 'stripe-checkout/diagnostics/run',
            'disabled' => $disabled,
            'icon' => 'refresh',
            'text' => self::translate('diagnostics.actions.runRemote'),
            'title' => $disabled ? self::translate('diagnostics.actions.runRemoteUnavailable') : null,
        ];
    }

    /** @return DiagnosticItem */
    private static function remoteSummaryItem(App $kirby, ?DiagnosticReport $report): array
    {
        $message = $report === null
            ? self::translate('diagnostics.remoteReport.never')
            : self::template('diagnostics.remoteReport.ready', [
                'checkedAt' => gmdate('Y-m-d H:i \U\T\C', $report->checkedAt()->getTimestamp()),
            ]);

        $item = self::item(
            kirby: $kirby,
            id: 'remote-summary',
            label: self::translate('diagnostics.remoteSummary'),
            message: $message,
            status: DiagnosticStatus::Pass,
        );
        // This row is run metadata; individual Stripe checks carry health status.
        $item['image']['icon'] = 'clock';
        $item['theme'] = 'info';

        return $item;
    }

    /**
     * @param list<DiagnosticItem> $items
     * @param DiagnosticAction|null $action
     * @return DiagnosticSectionData
     */
    private static function section(string $name, array $items, ?array $action = null): array
    {
        return [
            'action' => $action,
            'headline' => self::translate('diagnostics.groups.' . $name),
            'items' => $items,
            'name' => $name,
        ];
    }

    /** @return list<DiagnosticSummaryReport> */
    private static function summaryReports(DiagnosticReport $report, ?DiagnosticReport $remoteReport): array
    {
        $setupChecks = self::checks($report, self::CHECKOUT_SETUP_CHECKS);
        $stripeChecks = [$remoteReport?->check('stripeAccount')];
        $webhookChecks = [
            $report->check('webhookRoute'),
            $report->check('webhookSecret'),
            $remoteReport?->check('stripeWebhook'),
        ];
        $operationChecks = self::checks($report, self::OPERATION_CHECKS);
        $remoteCheckedAt = $remoteReport === null
            ? self::translate('diagnostics.summary.notCheckedInfo')
            : self::template('diagnostics.summary.checkedAt', [
                'checkedAt' => gmdate('Y-m-d H:i \U\T\C', $remoteReport->checkedAt()->getTimestamp()),
            ]);
        $webhookInfo = self::hasIssues($webhookChecks)
            ? self::issueInfo($webhookChecks)
            : $remoteCheckedAt;

        return [
            self::summaryReport(
                label: self::translate('diagnostics.summary.setup'),
                status: self::combinedStatus($setupChecks),
                info: self::issueInfo($setupChecks),
            ),
            self::summaryReport(
                label: self::translate('diagnostics.summary.stripeAccount'),
                status: self::combinedStatus($stripeChecks),
                info: $remoteCheckedAt,
            ),
            self::summaryReport(
                label: self::translate('diagnostics.summary.webhooks'),
                status: self::combinedStatus($webhookChecks),
                info: $webhookInfo,
            ),
            self::summaryReport(
                label: self::translate('diagnostics.summary.operations'),
                status: self::combinedStatus($operationChecks),
                info: self::issueInfo($operationChecks),
            ),
        ];
    }

    /**
     * @param list<string> $codes
     * @return list<DiagnosticCheck>
     */
    private static function checks(DiagnosticReport $report, array $codes): array
    {
        return array_values(array_filter(array_map(
            static fn(string $code): ?DiagnosticCheck => $report->check($code),
            $codes,
        )));
    }

    /** @param list<DiagnosticCheck|null> $checks */
    private static function combinedStatus(array $checks): DiagnosticStatus
    {
        $statuses = array_map(
            static fn(?DiagnosticCheck $check): DiagnosticStatus => $check?->status() ?? DiagnosticStatus::NotVerifiable,
            $checks,
        );

        return match (true) {
            in_array(DiagnosticStatus::Fail, $statuses, true) => DiagnosticStatus::Fail,
            in_array(DiagnosticStatus::Warning, $statuses, true) => DiagnosticStatus::Warning,
            in_array(DiagnosticStatus::NotVerifiable, $statuses, true) => DiagnosticStatus::NotVerifiable,
            default => DiagnosticStatus::Pass,
        };
    }

    /** @param list<DiagnosticCheck|null> $checks */
    private static function issueInfo(array $checks): string
    {
        $issueCount = self::issueCount($checks);

        if ($issueCount === 0) {
            if (self::combinedStatus($checks) === DiagnosticStatus::NotVerifiable) {
                return self::translate('diagnostics.summary.unavailableInfo');
            }

            return self::translate('diagnostics.summary.noIssues');
        }

        return self::template(
            $issueCount === 1
                ? 'diagnostics.summary.issue.one'
                : 'diagnostics.summary.issue.many',
            ['count' => (string) $issueCount],
        );
    }

    /** @param list<DiagnosticCheck|null> $checks */
    private static function hasIssues(array $checks): bool
    {
        return self::issueCount($checks) > 0;
    }

    /** @param list<DiagnosticCheck|null> $checks */
    private static function issueCount(array $checks): int
    {
        return count(array_filter(
            $checks,
            static fn(?DiagnosticCheck $check): bool => $check?->status() === DiagnosticStatus::Fail
                || $check?->status() === DiagnosticStatus::Warning,
        ));
    }

    /** @return DiagnosticSummaryReport */
    private static function summaryReport(
        string $label,
        DiagnosticStatus $status,
        string $info,
    ): array {
        return [
            'label' => self::summaryValue($status),
            'value' => $label,
            'info' => $info,
            'icon' => self::statusIcon($status),
            'theme' => self::statusTheme($status),
        ];
    }

    private static function summaryValue(DiagnosticStatus $status): string
    {
        return match ($status) {
            DiagnosticStatus::Pass => self::translate('diagnostics.summary.ready'),
            DiagnosticStatus::Fail => self::translate('diagnostics.summary.failed'),
            DiagnosticStatus::Warning => self::translate('diagnostics.summary.needsAttention'),
            DiagnosticStatus::NotVerifiable => self::translate('diagnostics.summary.notChecked'),
        };
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

    private static function statusIcon(DiagnosticStatus $status): string
    {
        return match ($status) {
            DiagnosticStatus::Pass => 'check',
            DiagnosticStatus::Fail => 'alert',
            DiagnosticStatus::Warning => 'alert',
            default => 'question',
        };
    }

    private static function statusTheme(DiagnosticStatus $status): string
    {
        return match ($status) {
            DiagnosticStatus::Pass => 'positive',
            DiagnosticStatus::Fail => 'negative',
            DiagnosticStatus::Warning => 'notice',
            default => 'info',
        };
    }

    /** @return list<DiagnosticItemButton> */
    private static function checkButtons(App $kirby, DiagnosticCheck $check): array
    {
        if (self::needsAttention($check) === false) {
            return [];
        }

        return match (true) {
            $check->code() === 'currency' => self::settingsButton($kirby),
            in_array($check->code(), ['publishableKey', 'secretKey'], true)
                && $check->messageKey() === 'credential.missing' => self::stripeApiKeysButton(),
            $check->code() === 'webhookSecret'
                && $check->messageKey() === 'credential.missing' => self::stripeWebhooksButton(),
            $check->code() === 'stripeAccount'
                && in_array($check->messageKey(), ['stripeAccount.modeMismatch', 'remote.authenticationFailed'], true) => self::stripeApiKeysButton(),
            $check->code() === 'stripeWebhook'
                && in_array($check->messageKey(), ['stripeWebhook.missing', 'stripeWebhook.incomplete', 'stripeWebhook.duplicate'], true) => self::stripeWebhooksButton(),
            $check->code() === 'events'
                && $check->messageKey() === 'events.failed' => self::ordersButton($kirby, 'failed_events'),
            $check->code() === 'housekeepingRun' => self::housekeepingButton($kirby),
            $check->code() === 'lifecycle'
                && $check->messageKey() === 'lifecycle.failed' => self::ordersButton($kirby, 'failed_hooks'),
            $check->code() === 'prices' => self::catalogueRefreshButton(
                kirby: $kirby,
                permission: 'prices.read',
                endpoint: 'stripe-checkout/diagnostics/prices/refresh',
                label: 'prices.refresh',
                successMessage: 'prices.refreshSuccess',
            ),
            $check->code() === 'taxCodes' => self::catalogueRefreshButton(
                kirby: $kirby,
                permission: 'taxCodes.read',
                endpoint: 'stripe-checkout/diagnostics/tax-codes/refresh',
                label: 'taxCodes.refresh',
                successMessage: 'taxCodes.refreshSuccess',
            ),
            default => [],
        };
    }

    /** @return list<DiagnosticItemButton> */
    private static function catalogueRefreshButton(
        App $kirby,
        string $permission,
        string $endpoint,
        string $label,
        string $successMessage,
    ): array {
        if (
            PluginPermissions::allows($kirby, $permission) === false
            || self::isStripeServerConfigurationReady($kirby) === false
        ) {
            return [];
        }

        return [[
            'endpoint' => $endpoint,
            'icon' => 'refresh',
            'successMessage' => self::translate($successMessage),
            'title' => self::translate($label),
            'variant' => 'filled',
        ]];
    }

    /** @return list<DiagnosticItemButton> */
    private static function housekeepingButton(App $kirby): array
    {
        if (
            PluginPermissions::allows($kirby, 'housekeeping.run') === false
            || self::isStripeServerConfigurationReady($kirby) === false
        ) {
            return [];
        }

        return [[
            'dialog' => 'stripe-checkout/housekeeping/run',
            'icon' => 'refresh',
            'title' => self::translate('housekeeping.actions.run'),
            'variant' => 'filled',
        ]];
    }

    /** @return list<DiagnosticItemButton> */
    private static function ordersButton(App $kirby, string $attention): array
    {
        if (PluginPermissions::allows($kirby, 'orders.read') === false) {
            return [];
        }

        return [[
            'icon' => 'cart',
            'link' => Panel::url('stripe-checkout') . '?tab=orders&checkoutStatus=&payment=&attention=' . $attention,
            'title' => self::translate('diagnostics.actions.viewAffectedOrders'),
            'variant' => 'filled',
        ]];
    }

    /** @return list<DiagnosticItemButton> */
    private static function stripeApiKeysButton(): array
    {
        return self::stripeDashboardButton(
            link: self::STRIPE_API_KEYS_URL,
            title: self::translate('diagnostics.actions.openStripeApiKeys'),
        );
    }

    /** @return list<DiagnosticItemButton> */
    private static function stripeWebhooksButton(): array
    {
        return self::stripeDashboardButton(
            link: self::STRIPE_WEBHOOKS_URL,
            title: self::translate('diagnostics.actions.openStripeWebhooks'),
        );
    }

    /** @return list<DiagnosticItemButton> */
    private static function stripeDashboardButton(string $link, string $title): array
    {
        return [[
            'icon' => 'open',
            'link' => $link,
            'rel' => 'noopener noreferrer',
            'target' => '_blank',
            'title' => $title,
            'variant' => 'filled',
        ]];
    }

    /** @return list<DiagnosticItemButton> */
    private static function settingsButton(App $kirby): array
    {
        if (PluginPermissions::allows($kirby, 'settings.read') === false) {
            return [];
        }

        return [[
            'icon' => 'settings',
            'link' => Panel::url('stripe-checkout') . '?tab=settings',
            'title' => self::translate('diagnostics.actions.openSettings'),
            'variant' => 'filled',
        ]];
    }
}
