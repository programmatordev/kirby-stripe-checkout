<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics\Internal;

use Kirby\Cache\Cache;
use ProgrammatorDev\StripeCheckout\Diagnostics\DiagnosticReport;
use Throwable;

/** @internal Caches only the latest sanitized explicit remote report. */
final readonly class DiagnosticReportStore
{
    private const KEY = 'latest';

    public function __construct(private Cache $cache) {}

    public function read(): ?DiagnosticReport
    {
        try {
            $report = $this->cache->get(self::KEY);

            return $report === null ? null : DiagnosticReport::fromArray($report);
        } catch (Throwable) {
            // Cached diagnostics are replaceable; an unreadable value is equivalent to no previous run.
            return null;
        }
    }

    public function write(DiagnosticReport $report): void
    {
        try {
            $this->cache->set(self::KEY, $report->toArray());
        } catch (Throwable) {
            // A replaceable cached copy must not turn a completed diagnostic run into a failure.
        }
    }
}
