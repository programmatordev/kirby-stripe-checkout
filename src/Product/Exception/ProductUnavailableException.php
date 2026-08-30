<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use Throwable;

final class ProductUnavailableException extends ProductException
{
    public function __construct(
        string $errorCode = 'product.unavailable',
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $previous);
    }
}
