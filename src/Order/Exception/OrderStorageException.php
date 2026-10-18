<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Exception;

use ProgrammatorDev\StripeCheckout\Kirby\PersistenceErrorCode;
use RuntimeException;

/** A storage failure without content, filesystem paths or underlying exception data. */
final class OrderStorageException extends RuntimeException
{
    public function __construct(private readonly string $storageErrorCode)
    {
        parent::__construct('Stripe Checkout order storage failed (' . $storageErrorCode . ').');
    }

    public function errorCode(): string
    {
        return $this->storageErrorCode;
    }

    /** Temporary persistence failures can warrant another explicit operation attempt. */
    public function isRetryable(): bool
    {
        return in_array($this->storageErrorCode, [
            PersistenceErrorCode::BUSY,
            PersistenceErrorCode::WRITE_FAILED,
            PersistenceErrorCode::VERIFY_FAILED,
        ], true);
    }
}
