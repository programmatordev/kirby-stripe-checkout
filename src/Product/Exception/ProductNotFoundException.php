<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use Throwable;

final class ProductNotFoundException extends ProductException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(ProductErrorCode::NOT_FOUND, $previous);
    }
}
