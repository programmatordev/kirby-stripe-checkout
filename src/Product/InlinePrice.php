<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use Throwable;

/** Contains an exact Kirby-owned unit price. */
final readonly class InlinePrice
{
    public function __construct(private Money $unitPrice)
    {
        try {
            (new StripeCurrencyRegistry())->fromMoney($this->unitPrice);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.price_invalid', $error);
        }
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }
}
