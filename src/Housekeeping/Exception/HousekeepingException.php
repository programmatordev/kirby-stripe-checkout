<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping\Exception;

use RuntimeException;

/** A sanitized housekeeping infrastructure failure. */
final class HousekeepingException extends RuntimeException
{
    private const ERROR_MESSAGE = 'Stripe Checkout housekeeping failed (%s).';

    public function __construct(private readonly string $errorCode)
    {
        parent::__construct(sprintf(self::ERROR_MESSAGE, $errorCode));
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
