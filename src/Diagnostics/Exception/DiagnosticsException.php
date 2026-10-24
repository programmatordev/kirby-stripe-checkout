<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics\Exception;

use RuntimeException;
use Throwable;

/** Carries a safe diagnostic error code while preserving the underlying provider failure internally. */
final class DiagnosticsException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, previous: $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
