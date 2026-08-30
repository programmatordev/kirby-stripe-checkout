<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use RuntimeException;
use Throwable;

/**
 * Base failure for safe product lookup, validation, and availability errors.
 */
class ProductException extends RuntimeException
{
    public function __construct(
        private readonly string $productErrorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Stripe Checkout product resolution failed (%s).', $productErrorCode),
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return $this->productErrorCode;
    }
}
