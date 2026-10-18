<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use LogicException;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Order\PaymentAction;

/** @internal Committed Page or safe failure details; never retains provider exceptions. */
final readonly class ReconciliationResult
{
    private function __construct(
        private ReconciliationOutcome $outcome,
        private ?OrderPage $orderPage,
        private ?string $paymentIntentId = null,
        private ?PaymentAction $nextAction = null,
        private ?string $errorCode = null,
        private bool $retryable = false,
    ) {}

    public static function committed(
        OrderPage $orderPage,
        bool $updated,
        ?string $paymentIntentId = null,
        ?PaymentAction $nextAction = null,
    ): self {
        if (($paymentIntentId === null) !== ($nextAction === null)) {
            throw new LogicException('A reconciliation payment action requires its PaymentIntent.');
        }

        return new self(
            outcome: $updated ? ReconciliationOutcome::Updated : ReconciliationOutcome::NoChange,
            orderPage: $orderPage,
            paymentIntentId: $paymentIntentId,
            nextAction: $nextAction,
        );
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

    /** Current provider action observed by this reconciliation, never a permanent Order fact. */
    public function nextAction(): ?PaymentAction
    {
        return $this->nextAction;
    }

    public function paymentIntentId(): ?string
    {
        return $this->paymentIntentId;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorCodeOrFail(): string
    {
        return $this->errorCode ?? throw new LogicException('A successful reconciliation has no error code.');
    }

    /** A temporary failure can warrant another explicit invocation; this does not schedule a retry. */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
