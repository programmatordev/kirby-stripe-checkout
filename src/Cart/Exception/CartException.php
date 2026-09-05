<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Exception;

use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;
use RuntimeException;

/** Rejected operation; conflicts include a fresh, safe Cart for recovery. */
final class CartException extends RuntimeException
{
    /** @internal Raw exceptions are deliberately not chained into the public response. */
    public function __construct(
        private readonly CartError $error,
        private readonly ?Cart $cart = null,
    ) {
        parent::__construct($error->message());
    }

    public function error(): CartError
    {
        return $this->error;
    }

    public function errorCode(): string
    {
        return $this->error->code();
    }

    public function cart(): ?Cart
    {
        return $this->cart;
    }
}
