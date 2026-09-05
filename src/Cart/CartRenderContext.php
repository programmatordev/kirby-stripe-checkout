<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

/** The authoritative HTTP result supplied to a project's fragment renderer. */
final readonly class CartRenderContext
{
    public function __construct(
        private CartOperation $operation,
        private int $status,
        private ?CartError $error = null,
    ) {}

    public function operation(): CartOperation
    {
        return $this->operation;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function error(): ?CartError
    {
        return $this->error;
    }
}
