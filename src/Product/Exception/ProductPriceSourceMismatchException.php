<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;

final class ProductPriceSourceMismatchException extends ProductException
{
    public function __construct()
    {
        parent::__construct(ProductErrorCode::PRICE_SOURCE_MISMATCH);
    }
}
