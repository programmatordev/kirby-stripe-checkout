<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Exception;

use RuntimeException;

/** Reports a stable code without echoing customer data or callback failures. */
final class OrderDataException extends RuntimeException
{
    public function __construct(private readonly string $orderErrorCode = 'order.data_invalid')
    {
        parent::__construct(sprintf('Stripe Checkout order data is invalid (%s).', $orderErrorCode));
    }

    public function errorCode(): string
    {
        return $this->orderErrorCode;
    }
}
