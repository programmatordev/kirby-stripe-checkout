<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

/** @internal Validated HTTP input for one revision-bound Cart item update. */
final readonly class CartItemUpdate
{
    public function __construct(
        private int $quantity,
        private string $revision,
    ) {}

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function revision(): string
    {
        return $this->revision;
    }
}
