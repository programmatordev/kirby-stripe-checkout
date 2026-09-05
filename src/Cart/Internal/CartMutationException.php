<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use RuntimeException;

/** @internal Carries current state on conflicts for the later safe Cart response. */
final class CartMutationException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        private readonly ?CartSnapshot $current = null,
    ) {
        parent::__construct('Stripe Checkout cart mutation rejected (' . $errorCode . ').');
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function current(): ?CartSnapshot
    {
        return $this->current;
    }
}
