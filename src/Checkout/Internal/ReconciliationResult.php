<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use LogicException;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;

/** @internal Committed Page or safe failure details; never retains provider exceptions. */
final readonly class ReconciliationResult
{
    private function __construct(
        private ReconciliationOutcome $outcome,
        private ?OrderPage $orderPage,
        private ?string $errorCode = null,
        private bool $retryable = false,
    ) {}

    public static function committed(OrderPage $orderPage, bool $updated): self
    {
        return new self($updated ? ReconciliationOutcome::Updated : ReconciliationOutcome::NoChange, $orderPage);
    }

    public static function failed(string $errorCode, bool $retryable = false): self
    {
        return new self(
            outcome: ReconciliationOutcome::Failed,
            orderPage: null,
            errorCode: $errorCode,
            retryable: $retryable,
        );
    }

    public function outcome(): ReconciliationOutcome
    {
        return $this->outcome;
    }

    public function orderPage(): ?OrderPage
    {
        return $this->orderPage;
    }

    public function orderPageOrFail(): OrderPage
    {
        return $this->orderPage ?? throw new LogicException('A failed reconciliation has no committed order Page.');
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
