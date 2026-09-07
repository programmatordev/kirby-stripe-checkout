<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Exception;

use RuntimeException;

/** Distinguishes unavailable storage from an empty collection or a non-disclosing miss. */
final class OrderQueryException extends RuntimeException
{
    public function __construct(private readonly string $queryErrorCode = 'order.query_unavailable')
    {
        parent::__construct('Stripe Checkout orders cannot be queried (' . $queryErrorCode . ').');
    }

    public function errorCode(): string
    {
        return $this->queryErrorCode;
    }
}
