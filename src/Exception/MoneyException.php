<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Exception;

use InvalidArgumentException;
use Throwable;

/**
 * Reports a stable money input or presentation failure without exposing values.
 */
final class MoneyException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $moneyErrorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Invalid Stripe Checkout money operation (%s).', $this->moneyErrorCode),
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return $this->moneyErrorCode;
    }
}
