<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Kirby\Cms\App;
use Kirby\Toolkit\I18n;
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
                'label' => self::translate('diagnostics.status.' . $report['status']),
                'text' => self::translate('diagnostics.description'),
                'icon' => self::statusIcon($report['status']),
                'theme' => self::statusTheme($report['status']),
            ],
        ];

        foreach ($report['checks'] as $check) {
            $values = $check['values'];

            if (isset($values['mode'])) {
                $values['mode'] = self::translate('credentialMode.' . $values['mode']);
            }

            if (isset($values['code'])) {
                $values['code'] = self::translate('error.' . $values['code']);
            }

            $sections['diagnostics-' . $check['id']] = [
                'type' => 'info',
                'label' => self::translate('diagnostics.' . $check['id']),
                'text' => self::template('diagnostics.' . $check['message'], $values),
                'icon' => self::statusIcon($check['status']),
                'theme' => self::statusTheme($check['status']),
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
