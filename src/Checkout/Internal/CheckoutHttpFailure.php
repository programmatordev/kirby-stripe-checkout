<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutError;

/** @internal One safe Checkout error with its HTTP classification. */
final readonly class CheckoutHttpFailure
{
    public function __construct(
        private CheckoutError $error,
        private int $httpStatus,
        private bool $requiresExistingAction,
    ) {}

    public function error(): CheckoutError
    {
        return $this->error;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function requiresExistingAction(): bool
    {
        return $this->requiresExistingAction;
    }
}
