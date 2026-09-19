<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

use Brick\Money\Currency;
use Brick\Money\Money;
use Closure;
use ProgrammatorDev\StripeCheckout\Cart\Exception\CartException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutationException;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartMutator;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Cart\Internal\CartViewFactory;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\ProductRequestData;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use Throwable;

/**
 * Reads and changes the current browser's cart. Successful mutations refresh
 * this object's presentation; previously returned CartItems remain immutable.
 * Revision-bound mutations default to this view's revision. HTTP callers must
 * pass the revision submitted by the visitor, not one freshly read on the server.
 */
final class Cart
{
    /**
     * @internal Constructed by the Site-scoped plugin entry point.
     * @param list<CartItem> $items
     * @param array<string, string> $shippingCountryOptions
     * @param list<CartError> $errors
     */
    public function __construct(
        private CartSnapshot $snapshot,
        private array $items,
        private ?Currency $currency,
        private ?Money $subtotal,
        private ?ShippingQuote $shippingQuote,
        private array $shippingCountryOptions,
        private array $errors,
        private readonly CartMutator $mutator,
        private readonly CartViewFactory $views,
        private bool $presentationResolved = true,
    ) {}

    /** @param array<string, string> $options */
    public function add(string $reference, int $quantity = 1, array $options = []): self
    {
        return $this->mutate(fn(): CartSnapshot => $this->mutator->add(ProductRequestData::parse([
            'reference' => $reference,
            'quantity' => $quantity,
            'selectedOptions' => $options,
        ])));
    }

    public function update(string $itemId, int $quantity, ?string $revision = null): self
    {
        return $this->mutate(fn(): CartSnapshot => $this->mutator->update($itemId, $quantity, $revision ?? $this->revision()));
    }

    public function remove(string $itemId, ?string $revision = null): self
    {
        return $this->mutate(fn(): CartSnapshot => $this->mutator->remove($itemId, $revision ?? $this->revision()));
    }

    public function updateShippingCountry(?string $shippingCountry, ?string $revision = null): self
    {
        return $this->mutate(fn(): CartSnapshot => $this->mutator->updateShippingCountry(
            $shippingCountry,
            $revision ?? $this->revision(),
        ));
    }

    public function clear(?string $revision = null): self
    {
        return $this->mutate(fn(): CartSnapshot => $this->mutator->clear($revision ?? $this->revision()));
    }

    /** @return list<CartItem> */
    public function items(): array
    {
        $this->resolvePresentation();

        return $this->items;
    }

    public function item(string $id): ?CartItem
    {
        foreach ($this->items() as $item) {
            if ($item->id() === $id) {
                return $item;
            }
        }

        return null;
    }

    public function revision(): string
    {
        return $this->snapshot->revision();
    }

    public function shippingCountry(): ?string
    {
        return $this->snapshot->shippingCountry();
    }

    /** @return array<string, string> Supported code-to-name choices for this Cart. */
    public function shippingCountryOptions(): array
    {
        $this->resolvePresentation();

        return $this->shippingCountryOptions;
    }

    public function count(): int
    {
        return count($this->items());
    }

    public function totalQuantity(): int
    {
        return array_reduce($this->items(), static fn(int $total, CartItem $item): int => $total + $item->quantity(), 0);
    }

    public function currency(): ?Currency
    {
        $this->resolvePresentation();

        return $this->currency;
    }

    public function subtotal(): ?Money
    {
        $this->resolvePresentation();

        return $this->subtotal;
    }

    /** Preview only; Checkout resolves shipping again before creating a Session. */
    public function shippingQuote(): ?ShippingQuote
    {
        $this->resolvePresentation();

        return $this->shippingQuote;
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /** @return list<CartError> */
    public function errors(): array
    {
        $this->resolvePresentation();

        return $this->errors;
    }

    /** @param Closure(): CartSnapshot $operation */
    private function mutate(Closure $operation): self
    {
        try {
            $next = $this->views->create($operation(), $this->mutator);
        } catch (Throwable $error) {
            $current = $error instanceof CartMutationException ? $error->current() : null;

            // Keep the caller's stale view unchanged; conflict recovery gets
            // a separate current Cart rather than silently retrying the write.
            throw new CartException(
                $this->views->error($error),
                $current === null ? null : $this->views->create($current, $this->mutator),
            );
        }

        $this->applyView($next);

        return $this;
    }

    private function resolvePresentation(): void
    {
        // HTTP mutations start with selection state only. Resolve on a read or
        // after the write, so clearing never needs the discarded products first.
        if ($this->presentationResolved === false) {
            $this->applyView($this->views->create($this->snapshot, $this->mutator));
        }
    }

    private function applyView(self $next): void
    {
        $this->snapshot = $next->snapshot;
        $this->items = $next->items;
        $this->currency = $next->currency;
        $this->subtotal = $next->subtotal;
        $this->shippingQuote = $next->shippingQuote;
        $this->shippingCountryOptions = $next->shippingCountryOptions;
        $this->errors = $next->errors;
        $this->presentationResolved = true;
    }
}
