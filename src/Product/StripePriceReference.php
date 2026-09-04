<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;

/** Identifies a Stripe Price that still requires authoritative resolution. */
final readonly class StripePriceReference
{
    public function __construct(private string $priceId)
    {
        if (preg_match('/^price_[A-Za-z0-9]{1,249}$/D', $this->priceId) !== 1) {
            throw new InvalidProductException('product.stripe_price_invalid');
        }
    }

    public function priceId(): string
    {
        return $this->priceId;
    }
}
