<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use Throwable;

/** Contains an exact Kirby-owned unit price. */
final readonly class Price
{
    public function __construct(private Money $price)
    {
        try {
            (new StripeCurrencyRegistry())->fromMoney($this->price);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.price_invalid', $error);
        }
    }

    public function price(): Money
    {
        return $this->price;
    }
}
