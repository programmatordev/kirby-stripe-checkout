<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Toolkit\I18n;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;
use ProgrammatorDev\StripeCheckout\Diagnostics\LocalDiagnostics;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;

/**
 * Maps local diagnostics to Kirby-native info sections.
 *
 * @internal
 */
final class DiagnosticsSections
{
    /** @return array<string, array<string, mixed>> */
    public static function build(App $kirby): array
    {
        $report = (new LocalDiagnostics($kirby))->report();
        $sections = [
            'diagnostics-summary' => [
                'type' => 'info',
                'label' => self::translate('diagnostics.status.' . $report->status()->value),
                'text' => self::translate('diagnostics.description'),
                'icon' => self::statusIcon($report->status()),
                'theme' => self::statusTheme($report->status()),
            ],
        ];

        foreach ($report->checks() as $check) {
            $context = $check->context();

            if (isset($context['mode'])) {
                $context['mode'] = self::translate('credentialMode.' . $context['mode']);
            }

            if (isset($context['code'])) {
                $context['code'] = self::translate('error.' . $context['code']);
            }

            $sections['diagnostics-' . $check->code()] = [
                'type' => 'info',
                'label' => self::translate('diagnostics.' . $check->code()),
                'text' => self::template('diagnostics.' . $check->message(), $context),
                'icon' => self::statusIcon($check->status()),
                'theme' => self::statusTheme($check->status()),
            ];
        }

        return $sections;
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
