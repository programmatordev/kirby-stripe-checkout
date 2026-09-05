<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

use Brick\Money\Money;
use Kirby\Cms\File;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;

/** One immutable resolved line; unavailable selections remain removable. */
final readonly class CartItem
{
    /**
     * @internal Constructed from stored selection and current product resolution.
     * @param list<CartError> $errors
     */
    public function __construct(
        private string $id,
        private ProductRequest $request,
        private ?ResolvedProduct $product,
        private ?Money $price,
        private ?Money $subtotal,
        private array $errors = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function request(): ProductRequest
    {
        return $this->request;
    }

    public function quantity(): int
    {
        return $this->request->quantity();
    }

    public function product(): ?ResolvedProduct
    {
        return $this->product;
    }

    /** Returns the original Kirby image for crops/thumbs, or null for external URLs. */
    public function image(): ?File
    {
        return $this->product?->image();
    }

    /** @return list<SelectedOption> Empty when there are no options or resolution failed. */
    public function options(): array
    {
        return $this->product?->selectedOptions() ?? [];
    }

    /** Effective price of one selected unit, regardless of Kirby or Stripe source. */
    public function price(): ?Money
    {
        return $this->price;
    }

    /** Merchandise amount for this quantity, before shipping/discount/tax adjustments. */
    public function subtotal(): ?Money
    {
        return $this->subtotal;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /** @return list<CartError> */
    public function errors(): array
    {
        return $this->errors;
    }
}
