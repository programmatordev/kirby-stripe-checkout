<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use Throwable;

final class InvalidProductException extends ProductException
{
    public function __construct(
        string $errorCode = 'product.invalid',
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $previous);
    }
}
