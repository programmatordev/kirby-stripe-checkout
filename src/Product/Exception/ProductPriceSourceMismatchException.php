<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Exception;

final class ProductPriceSourceMismatchException extends ProductException
{
    public function __construct()
    {
        parent::__construct('product.price_source_mismatch');
    }
}
