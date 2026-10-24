<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

use DateTimeImmutable;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/** A timestamped collection of sanitized checks from one diagnostic run. */
final readonly class DiagnosticReport
{
    private const INVALID_REPORT_MESSAGE = 'Invalid diagnostic report.';

    /** @param list<DiagnosticCheck> $checks */
    public function __construct(
        private DateTimeImmutable $checkedAt,
        private array $checks,
    ) {
        $codes = array_map(
            static fn(DiagnosticCheck $check): string => $check->code(),
            $checks,
        );

        if ($checks === [] || count(array_unique($codes)) !== count($codes)) {
            throw new InvalidArgumentException(self::INVALID_REPORT_MESSAGE);
        }
    }

    public function checkedAt(): DateTimeImmutable
    {
        return $this->checkedAt;
    }

    /** @return list<DiagnosticCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function check(string $code): ?DiagnosticCheck
    {
        foreach ($this->checks as $check) {
            if ($check->code() === $code) {
                return $check;
            }
        }

        return null;
    }

    public function status(): DiagnosticStatus
    {
        $statuses = array_map(
            static fn(DiagnosticCheck $check): DiagnosticStatus => $check->status(),
            $this->checks,
        );

        return match (true) {
            in_array(DiagnosticStatus::Fail, $statuses, true) => DiagnosticStatus::Fail,
            in_array(DiagnosticStatus::Warning, $statuses, true) => DiagnosticStatus::Warning,
            in_array(DiagnosticStatus::NotVerifiable, $statuses, true) => DiagnosticStatus::NotVerifiable,
            default => DiagnosticStatus::Pass,
        };
    }

    /** @return array{checkedAt: string, status: string, checks: list<array{code: string, status: string, messageKey: string, context: array<string, string>, affectedOperations: list<string>}>} */
    public function toArray(): array
    {
        return [
            'checkedAt' => OrderData::timestamp($this->checkedAt),
            'status' => $this->status()->value,
            'checks' => array_map(
                static fn(DiagnosticCheck $check): array => $check->toArray(),
                $this->checks,
            ),
        ];
    }

    public static function fromArray(mixed $data): self
    {
        try {
            if (is_array($data) === false) {
                throw new InvalidArgumentException(self::INVALID_REPORT_MESSAGE);
            }

            $keys = self::keys();

            if (array_diff(array_keys($data), $keys) !== [] || array_diff($keys, array_keys($data)) !== []) {
                throw new InvalidArgumentException(self::INVALID_REPORT_MESSAGE);
            }

            $checks = array_map(
                static fn(mixed $check): DiagnosticCheck => DiagnosticCheck::fromArray($check),
                OrderData::list($data['checks']),
            );
            $report = new self(
                checkedAt: OrderData::date($data['checkedAt']),
                checks: $checks,
            );

            if ($report->status()->value !== $data['status']) {
                throw new InvalidArgumentException(self::INVALID_REPORT_MESSAGE);
            }

            return $report;
        } catch (Throwable $error) {
            throw new InvalidArgumentException(self::INVALID_REPORT_MESSAGE, previous: $error);
        }
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return [
            'checkedAt',
            'status',
            'checks',
        ];
    }
}
