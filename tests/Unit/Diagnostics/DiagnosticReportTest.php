<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Diagnostics;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticCheck;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticReport;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticStatus;

final class DiagnosticReportTest extends TestCase
{
    public function testPersistsOnlyTheSanitizedDiagnosticContract(): void
    {
        $report = new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-10-23T10:15:00Z'),
            checks: [
                new DiagnosticCheck(
                    code: 'configuration',
                    status: DiagnosticStatus::Fail,
                    messageKey: 'configuration.invalid',
                    context: ['code' => 'configuration.required_missing'],
                    affectedOperations: ['checkout'],
                ),
                new DiagnosticCheck(
                    code: 'provider',
                    status: DiagnosticStatus::NotVerifiable,
                    messageKey: 'provider.permissionDenied',
                ),
            ],
        );

        $restored = DiagnosticReport::fromArray($report->toArray());
        $configuration = $restored->check('configuration');

        $this->assertSame(DiagnosticStatus::Fail, $restored->status());
        $this->assertSame('2026-10-23T10:15:00Z', $restored->toArray()['checkedAt']);
        $this->assertNotNull($configuration);
        $this->assertSame(['checkout'], $configuration->affectedOperations());
        $this->assertSame(
            ['code' => 'configuration.required_missing'],
            $configuration->context(),
        );
    }

    public function testRejectsAPersistedOverallStatusThatDoesNotMatchItsChecks(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DiagnosticReport::fromArray([
            'checkedAt' => '2026-10-23T10:15:00Z',
            'status' => 'pass',
            'checks' => [[
                'code' => 'provider',
                'status' => 'fail',
                'messageKey' => 'provider.failed',
                'context' => [],
                'affectedOperations' => [],
            ]],
        ]);
    }

    public function testRejectsDuplicateCheckCodes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-10-23T10:15:00Z'),
            checks: [
                new DiagnosticCheck('provider', DiagnosticStatus::Pass, 'provider.ready'),
                new DiagnosticCheck('provider', DiagnosticStatus::Warning, 'provider.warning'),
            ],
        );
    }

    public function testRejectsAnEmptyReport(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiagnosticReport(
            checkedAt: new DateTimeImmutable('2026-10-23T10:15:00Z'),
            checks: [],
        );
    }
}
