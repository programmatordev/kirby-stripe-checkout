<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** Identifies a Stripe Price that still requires authoritative resolution. */
final readonly class StripePriceReference
{
    public function __construct(private string $priceId)
    {
        if ($this->priceId === '' || TextValidator::isUtf8($this->priceId) === false) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INVALID);
        }
    }

    public function priceId(): string
    {
        return $this->priceId;
    }
}
