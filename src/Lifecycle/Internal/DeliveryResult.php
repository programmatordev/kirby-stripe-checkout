<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle\Internal;

/**
 * @internal Recorded delivery success or safe failure; contains no private event payload or exception.
 * A bookkeeping failure can be reported after listeners have already run.
 */
final readonly class DeliveryResult
{
    private function __construct(
        private ?string $errorCode,
        private bool $retryable = false,
    ) {}

    public static function delivered(): self
    {
        return new self(null);
    }

    public static function failed(string $errorCode, bool $retryable = false): self
    {
        return new self($errorCode, $retryable);
    }

    public function isDelivered(): bool
    {
        return $this->errorCode === null;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    /** Another explicit attempt can help; this never schedules or performs one. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
