<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use Throwable;

final class InvalidProductException extends ProductException
{
    public function __construct(
        string $errorCode = ProductErrorCode::INVALID,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $previous);
    }
}
