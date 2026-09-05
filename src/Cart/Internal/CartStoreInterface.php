<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Closure;

/**
 * Internal atomic storage boundary, not a configurable public storage adapter.
 *
 * @internal
 */
interface CartStoreInterface
{
    public function read(): CartSnapshot;

    /**
     * Reload current state under the store lock, invoke once, then commit on success.
     * A thrown exception must leave the stored snapshot unchanged.
     *
     * @param Closure(CartSnapshot): CartSnapshot $mutation
     */
    public function mutate(Closure $mutation): CartSnapshot;
}
