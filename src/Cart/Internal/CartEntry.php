<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * One stable cart-item identity and its selection, with no cached product facts.
 *
 * @internal
 */
final readonly class CartEntry
{
    public function __construct(private string $id, private ProductRequest $request)
    {
        ProductData::identifier($id);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function request(): ProductRequest
    {
        return $this->request;
    }
}
