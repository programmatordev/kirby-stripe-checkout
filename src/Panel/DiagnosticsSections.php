<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticCheck;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Maps diagnostics to Kirby-native info fields.
 *
 * @internal
 */
final class DiagnosticsSections
{
    /** @return array<string, array<string, mixed>> */
    public static function build(App $kirby): array
    {
        $report = (new LocalDiagnostics($kirby))->report();
        $fields = [
            'diagnosticsSummary' => [
                'type' => 'info',
                'label' => self::translate('diagnostics.status.' . $report->status()->value),
                'text' => self::translate('diagnostics.description'),
                'icon' => self::statusIcon($report->status()),
                'theme' => self::statusTheme($report->status()),
            ],
        ];

        foreach ($report->checks() as $check) {
            $fields['diagnostics' . ucfirst($check->code())] = self::checkField($check);
        }

        $remoteReport = (new RuntimeFactory($kirby))->diagnosticReportStore()->read();

        if ($remoteReport === null) {
            $fields['diagnosticsRemoteSummary'] = [
                'type' => 'info',
                'label' => self::translate('diagnostics.remoteSummary'),
                'text' => self::translate('diagnostics.remoteReport.never'),
                'icon' => self::statusIcon(DiagnosticStatus::NotVerifiable),
                'theme' => self::statusTheme(DiagnosticStatus::NotVerifiable),
            ];
        } else {
            $fields['diagnosticsRemoteSummary'] = [
                'type' => 'info',
                'label' => self::translate('diagnostics.remoteSummary'),
                'text' => self::template('diagnostics.remoteReport.ready', [
                    'checkedAt' => gmdate('Y-m-d H:i \U\T\C', $remoteReport->checkedAt()->getTimestamp()),
                    'status' => self::translate('diagnostics.status.' . $remoteReport->status()->value),
                ]),
                'icon' => self::statusIcon($remoteReport->status()),
                'theme' => self::statusTheme($remoteReport->status()),
            ];

            foreach ($remoteReport->checks() as $check) {
                $fields['diagnosticsRemote' . ucfirst($check->code())] = self::checkField($check);
            }
        }

        // Kirby reloads native fields sections when the surrounding view changes.
        // A stable section name keeps its follow-up API endpoint valid while the
        // refreshed field definitions present the latest persisted results.
        return [
            'diagnostics' => [
                'type' => 'fields',
                'fields' => $fields,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function checkField(DiagnosticCheck $check): array
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

        return [
            'type' => 'info',
            'label' => self::translate('diagnostics.' . $check->code()),
            'text' => self::template('diagnostics.' . $check->messageKey(), $context),
            'icon' => self::statusIcon($check->status()),
            'theme' => self::statusTheme($check->status()),
        ];
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
            DiagnosticStatus::Warning => 'warning',
            default => 'info',
        };
    }
}
