<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Exception;

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
}
