<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping;

use DateTimeImmutable;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/** Sanitized counters and status for one bounded housekeeping invocation. */
final readonly class HousekeepingRunOutcome
{
    private const INVALID_OUTCOME_MESSAGE = 'Invalid housekeeping run outcome.';

    public function __construct(
        private DateTimeImmutable $attemptedAt,
        private DateTimeImmutable $completedAt,
        private HousekeepingRunStatus $status,
        private int $selectedOrders,
        private int $processedOrders,
        private int $prunedDeliveries,
        private int $deletedOrders,
        private int $recoveredOrders,
        private int $failedOrders,
        private ?string $errorCode,
    ) {
        $counts = [
            $selectedOrders,
            $processedOrders,
            $prunedDeliveries,
            $deletedOrders,
            $recoveredOrders,
            $failedOrders,
        ];

        if ($completedAt < $attemptedAt) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if (min($counts) < 0) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($processedOrders > $selectedOrders) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($failedOrders > $processedOrders) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($recoveredOrders > $processedOrders) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($deletedOrders > $processedOrders) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($status === HousekeepingRunStatus::Success && $failedOrders !== 0) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if (($status === HousekeepingRunStatus::Success) !== ($errorCode === null)) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if ($errorCode === '') {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }
    }

    public function status(): HousekeepingRunStatus
    {
        return $this->status;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'attemptedAt' => OrderData::timestamp($this->attemptedAt),
            'completedAt' => OrderData::timestamp($this->completedAt),
            'status' => $this->status->value,
            'selectedOrders' => $this->selectedOrders,
            'processedOrders' => $this->processedOrders,
            'prunedDeliveries' => $this->prunedDeliveries,
            'deletedOrders' => $this->deletedOrders,
            'recoveredOrders' => $this->recoveredOrders,
            'failedOrders' => $this->failedOrders,
            'errorCode' => $this->errorCode,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        if (is_array($data) === false) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if (array_diff(array_keys($data), self::keys()) !== []) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        if (array_diff(self::keys(), array_keys($data)) !== []) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
        }

        try {
            $status = is_string($data['status']) ? HousekeepingRunStatus::tryFrom($data['status']) : null;
            $errorCode = $data['errorCode'];

            if ($status === null) {
                throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
            }

            if ($errorCode !== null && is_string($errorCode) === false) {
                throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE);
            }

            return new self(
                attemptedAt: OrderData::date($data['attemptedAt']),
                completedAt: OrderData::date($data['completedAt']),
                status: $status,
                selectedOrders: OrderData::integer($data['selectedOrders']),
                processedOrders: OrderData::integer($data['processedOrders']),
                prunedDeliveries: OrderData::integer($data['prunedDeliveries']),
                deletedOrders: OrderData::integer($data['deletedOrders']),
                recoveredOrders: OrderData::integer($data['recoveredOrders']),
                failedOrders: OrderData::integer($data['failedOrders']),
                errorCode: $errorCode,
            );
        } catch (Throwable $error) {
            throw new InvalidArgumentException(self::INVALID_OUTCOME_MESSAGE, previous: $error);
        }
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return [
            'attemptedAt',
            'completedAt',
            'status',
            'selectedOrders',
            'processedOrders',
            'prunedDeliveries',
            'deletedOrders',
            'recoveredOrders',
            'failedOrders',
            'errorCode',
        ];
    }
}
