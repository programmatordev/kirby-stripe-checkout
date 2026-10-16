<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutResultReason;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

/** @internal Persistable browser and destination binding for one Checkout result. */
final readonly class CheckoutResultState
{
    public function __construct(
        private CheckoutResultReason $reason,
        private ?string $pageUuid,
        private ?string $userUuid,
        private ?string $guestReference,
        private string $destinationUrl,
        private bool $canResumeCheckout,
        private int $expiresAt,
    ) {
        if (($userUuid === null) === ($guestReference === null)) {
            throw new InvalidArgumentException('A Checkout result requires exactly one browser actor.');
        }

        if ($pageUuid !== null) {
            OrderData::uuid($pageUuid);
        }

        if (CheckoutUrlValidator::isPersistedDestination($destinationUrl, false) === false || $expiresAt < 0) {
            throw new InvalidArgumentException('The Checkout result is invalid.');
        }

        if ($canResumeCheckout && ($pageUuid === null || $reason !== CheckoutResultReason::EmbeddedReturn)) {
            throw new InvalidArgumentException('The Checkout result cannot resume.');
        }
    }

    public function reason(): CheckoutResultReason
    {
        return $this->reason;
    }

    public function pageUuid(): ?string
    {
        return $this->pageUuid;
    }

    public function canResumeCheckout(): bool
    {
        return $this->canResumeCheckout;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function matches(
        ?string $userUuid,
        ?string $guestReference,
        string $destinationUrl,
    ): bool {
        return $this->userUuid === $userUuid
            && $this->guestReference === $guestReference
            && hash_equals($this->destinationUrl, $destinationUrl);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason->value,
            'pageUuid' => $this->pageUuid,
            'userUuid' => $this->userUuid,
            'guestReference' => $this->guestReference,
            'destinationUrl' => $this->destinationUrl,
            'canResumeCheckout' => $this->canResumeCheckout,
            'expiresAt' => $this->expiresAt,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        if (
            is_array($data) === false
            || array_diff(array_keys($data), ['reason', 'pageUuid', 'userUuid', 'guestReference', 'destinationUrl', 'canResumeCheckout', 'expiresAt']) !== []
        ) {
            throw new InvalidArgumentException('Invalid Checkout result state.');
        }

        $reason = is_string($data['reason'] ?? null) ? CheckoutResultReason::tryFrom($data['reason']) : null;
        $pageUuid = self::nullableString($data, 'pageUuid');
        $userUuid = self::nullableString($data, 'userUuid');
        $guestReference = self::nullableString($data, 'guestReference');
        $destinationUrl = $data['destinationUrl'] ?? null;
        $canResumeCheckout = $data['canResumeCheckout'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;

        if (
            $reason === null
            || is_string($destinationUrl) === false
            || is_bool($canResumeCheckout) === false
            || is_int($expiresAt) === false
        ) {
            throw new InvalidArgumentException('Invalid Checkout result state.');
        }

        return new self(
            reason: $reason,
            pageUuid: $pageUuid,
            userUuid: $userUuid,
            guestReference: $guestReference,
            destinationUrl: $destinationUrl,
            canResumeCheckout: $canResumeCheckout,
            expiresAt: $expiresAt,
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (array_key_exists($key, $data) === false) {
            throw new InvalidArgumentException('Invalid Checkout result state.');
        }

        $value = $data[$key];

        if ($value !== null && (is_string($value) === false || $value === '')) {
            throw new InvalidArgumentException('Invalid Checkout result state.');
        }

        return $value;
    }
}
