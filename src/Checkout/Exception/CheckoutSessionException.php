<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Exception;

use RuntimeException;
use Throwable;

/** @internal Safe creation/reuse failure for the later HTTP presentation edge. */
final class CheckoutSessionException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, previous: $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
