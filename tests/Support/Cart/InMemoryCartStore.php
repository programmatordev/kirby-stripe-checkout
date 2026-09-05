<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support\Cart;

use Closure;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartStoreInterface;

/** Models commit-on-success so failed mutations cannot leak partially updated state. */
final class InMemoryCartStore implements CartStoreInterface
{
    public function __construct(private CartSnapshot $snapshot) {}

    public function read(): CartSnapshot
    {
        return $this->snapshot;
    }

    public function mutate(Closure $mutation): CartSnapshot
    {
        return $this->snapshot = $mutation($this->snapshot);
    }
}
