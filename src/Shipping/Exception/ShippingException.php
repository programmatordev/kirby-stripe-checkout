<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Exception;

use RuntimeException;
use Throwable;

/** Base failure for safe shipping quote resolution and validation errors. */
class ShippingException extends RuntimeException
{
    public function __construct(
        private readonly string $shippingErrorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Stripe Checkout shipping resolution failed (%s).', $shippingErrorCode),
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return $this->shippingErrorCode;
    }
}
