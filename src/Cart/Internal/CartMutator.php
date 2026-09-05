<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Applies selection mutations against current state inside the store's atomic operation.
 * Removal and clearing deliberately skip product resolution so an unavailable
 * product or provider cannot prevent the customer from emptying the cart.
 *
 * @internal
 */
final class CartMutator
{
    /** @var Closure(): string */
    private readonly Closure $newRevision;
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param Closure(): string $newId Supplied by the Kirby composition layer.
     * @param (Closure(): string)|null $newRevision
     * @param (Closure(): int)|null $clock
     */
    public function __construct(
        private readonly CartStoreInterface $store,
        private readonly SelectionCanonicalizer $selections,
        private readonly Closure $newId,
        ?Closure $newRevision = null,
        ?Closure $clock = null,
    ) {
        $this->newRevision = $newRevision ?? static fn(): string => bin2hex(random_bytes(16));
        $this->clock = $clock ?? time(...);
    }

    public function add(ProductRequest $request): CartSnapshot
    {
        // Add is relative to the latest quantity, so it needs no caller revision.
        return $this->store->mutate(function (CartSnapshot $current) use ($request): CartSnapshot {
            // Resolve aliases before comparing so page IDs and UUIDs can merge
            // into the same line when they identify the same product/options.
            $request = $this->selections->resolve($request);
            $entries = $current->entries();

            foreach ($entries as $index => $entry) {
                if (SelectionData::equivalent($entry->request(), $request)) {
                    $entries[$index] = new CartEntry($entry->id(), $this->selections->merge($entry->request(), $request));

                    return $this->changed($current, $entries);
                }
            }

            if (count($entries) >= SelectionCanonicalizer::MAX_ENTRIES) {
                throw new CheckoutInputException('selection.line_limit_exceeded');
            }

            $entries[] = new CartEntry(($this->newId)(), $request);

            return $this->changed($current, $entries);
        });
    }

    public function update(string $itemId, int $quantity, string $revision): CartSnapshot
    {
        return $this->store->mutate(function (CartSnapshot $current) use ($itemId, $quantity, $revision): CartSnapshot {
            $this->requireRevision($current, $revision);

            if ($quantity < 1) {
                throw new CheckoutInputException('selection.quantity_invalid');
            }

            $entries = $current->entries();
            $index = $this->itemIndex($current, $itemId);
            $entry = $entries[$index];
            // Even an unchanged quantity must still pass current product rules;
            // a successful no-op then preserves the original snapshot/revision.
            $request = $this->selections->withQuantity($entry->request(), $quantity);

            if ($quantity === $entry->request()->quantity()) {
                return $current;
            }

            $entries[$index] = new CartEntry($entry->id(), $request);

            return $this->changed($current, array_values($entries));
        });
    }

    public function remove(string $itemId, string $revision): CartSnapshot
    {
        return $this->store->mutate(function (CartSnapshot $current) use ($itemId, $revision): CartSnapshot {
            $this->requireRevision($current, $revision);
            $entries = $current->entries();
            unset($entries[$this->itemIndex($current, $itemId)]);

            return $this->changed($current, array_values($entries));
        });
    }

    public function clear(string $revision): CartSnapshot
    {
        return $this->store->mutate(function (CartSnapshot $current) use ($revision): CartSnapshot {
            $this->requireRevision($current, $revision);

            return $current->entries() === [] && $current->destinationCountry() === null
                ? $current
                : $this->changed($current, [], clearDestination: true);
        });
    }

    /** Clears only the exact originating cart; later verified Checkout bindings call this. */
    public function clearIfMatches(string $cartId, string $revision): CartSnapshot
    {
        return $this->store->mutate(function (CartSnapshot $current) use ($cartId, $revision): CartSnapshot {
            if ($current->id() !== $cartId || $current->revision() !== $revision) {
                return $current;
            }

            return $current->entries() === [] && $current->destinationCountry() === null
                ? $current
                : $this->changed($current, [], clearDestination: true);
        });
    }

    private function requireRevision(CartSnapshot $current, string $revision): void
    {
        if ($revision === '') {
            throw new CheckoutInputException('selection.invalid');
        }

        if ($revision !== $current->revision()) {
            throw new CartMutationException('cart.revision_conflict', $current);
        }
    }

    private function itemIndex(CartSnapshot $current, string $id): int
    {
        foreach ($current->entries() as $index => $entry) {
            if ($entry->id() === $id) {
                return $index;
            }
        }

        throw new CartMutationException('cart.item_not_found');
    }

    /** @param list<CartEntry> $entries */
    private function changed(CartSnapshot $current, array $entries, bool $clearDestination = false): CartSnapshot
    {
        return new CartSnapshot(
            $current->id(),
            ($this->newRevision)(),
            $entries,
            $current->createdAt(),
            // Clock adjustments must not move the stored update time backwards.
            max($current->updatedAt(), ($this->clock)()),
            $clearDestination ? null : $current->destinationCountry(),
        );
    }
}
