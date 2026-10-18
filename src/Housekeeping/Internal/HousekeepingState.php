<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SessionDiscoveryProgress;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunOutcome;
use ProgrammatorDev\StripeCheckout\Housekeeping\HousekeepingRunStatus;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

/** @internal Minimal continuation and outcome state shared by CLI and Panel runs. */
final readonly class HousekeepingState
{
    private const INVALID_STATE_MESSAGE = 'Invalid housekeeping state.';

    /**
     * @param list<SessionDiscoveryProgress> $discoveryProgress
     */
    public function __construct(
        private ?string $lastOrderPageUuid = null,
        private array $discoveryProgress = [],
        private ?HousekeepingRunOutcome $lastAttempt = null,
        private ?HousekeepingRunOutcome $lastSuccess = null,
    ) {
        if ($lastOrderPageUuid !== null) {
            OrderData::uuid($lastOrderPageUuid);
        }

        // Credential rotation replaces an order's search; two continuations for one order cannot both remain resumable.
        $pageUuids = [];

        foreach ($discoveryProgress as $progress) {
            if (isset($pageUuids[$progress->pageUuid])) {
                throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
            }

            $pageUuids[$progress->pageUuid] = true;
        }

        if ($lastSuccess !== null && $lastSuccess->status() !== HousekeepingRunStatus::Success) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }
    }

    public function lastOrderPageUuid(): ?string
    {
        return $this->lastOrderPageUuid;
    }

    /** @return list<SessionDiscoveryProgress> */
    public function discoveryProgress(): array
    {
        return $this->discoveryProgress;
    }

    public function progress(string $pageUuid, string $credentialFingerprint): ?SessionDiscoveryProgress
    {
        foreach ($this->discoveryProgress as $progress) {
            if ($progress->pageUuid === $pageUuid && $progress->credentialFingerprint === $credentialFingerprint) {
                return $progress;
            }
        }

        return null;
    }

    public function lastAttempt(): ?HousekeepingRunOutcome
    {
        return $this->lastAttempt;
    }

    public function lastSuccess(): ?HousekeepingRunOutcome
    {
        return $this->lastSuccess;
    }

    public function withLastOrderPageUuid(?string $lastOrderPageUuid): self
    {
        return new self(
            lastOrderPageUuid: $lastOrderPageUuid,
            discoveryProgress: $this->discoveryProgress,
            lastAttempt: $this->lastAttempt,
            lastSuccess: $this->lastSuccess,
        );
    }

    public function withProgress(SessionDiscoveryProgress $progress): self
    {
        $discoveryProgress = [];

        foreach ($this->discoveryProgress as $existing) {
            // A rotated credential starts a new search for this Order; its old cursor and candidate cannot remain reachable.
            if ($existing->pageUuid !== $progress->pageUuid) {
                $discoveryProgress[] = $existing;
            }
        }

        $discoveryProgress[] = $progress;

        return new self(
            lastOrderPageUuid: $this->lastOrderPageUuid,
            discoveryProgress: $discoveryProgress,
            lastAttempt: $this->lastAttempt,
            lastSuccess: $this->lastSuccess,
        );
    }

    public function withoutProgressForOrder(string $pageUuid): self
    {
        $discoveryProgress = array_values(array_filter(
            $this->discoveryProgress,
            static fn(SessionDiscoveryProgress $progress): bool => $progress->pageUuid !== $pageUuid,
        ));

        return new self(
            lastOrderPageUuid: $this->lastOrderPageUuid,
            discoveryProgress: $discoveryProgress,
            lastAttempt: $this->lastAttempt,
            lastSuccess: $this->lastSuccess,
        );
    }

    public function withOutcome(HousekeepingRunOutcome $outcome): self
    {
        return new self(
            lastOrderPageUuid: $this->lastOrderPageUuid,
            discoveryProgress: $this->discoveryProgress,
            lastAttempt: $outcome,
            lastSuccess: $outcome->status() === HousekeepingRunStatus::Success ? $outcome : $this->lastSuccess,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schemaVersion' => 1,
            'lastOrderPageUuid' => $this->lastOrderPageUuid,
            'discoveryProgress' => array_map(
                static fn(SessionDiscoveryProgress $progress): array => $progress->toArray(),
                $this->discoveryProgress,
            ),
            'lastAttempt' => $this->lastAttempt?->toArray(),
            'lastSuccess' => $this->lastSuccess?->toArray(),
        ];
    }

    public static function fromArray(mixed $data): self
    {
        $keys = ['schemaVersion', 'lastOrderPageUuid', 'discoveryProgress', 'lastAttempt', 'lastSuccess'];

        if (is_array($data) === false) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if (array_diff(array_keys($data), $keys) !== []) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if (array_diff($keys, array_keys($data)) !== []) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if ($data['schemaVersion'] !== 1) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if ($data['lastOrderPageUuid'] !== null && is_string($data['lastOrderPageUuid']) === false) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if (is_array($data['discoveryProgress']) === false) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        if (array_is_list($data['discoveryProgress']) === false) {
            throw new InvalidArgumentException(self::INVALID_STATE_MESSAGE);
        }

        return new self(
            lastOrderPageUuid: $data['lastOrderPageUuid'],
            discoveryProgress: array_map(
                static fn(mixed $progress): SessionDiscoveryProgress => SessionDiscoveryProgress::fromArray($progress),
                $data['discoveryProgress'],
            ),
            lastAttempt: $data['lastAttempt'] === null ? null : HousekeepingRunOutcome::fromArray($data['lastAttempt']),
            lastSuccess: $data['lastSuccess'] === null ? null : HousekeepingRunOutcome::fromArray($data['lastSuccess']),
        );
    }
}
