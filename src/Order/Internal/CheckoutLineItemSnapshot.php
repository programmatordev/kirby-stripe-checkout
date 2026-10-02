<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;

/** @internal One returned Checkout line, correlated to its frozen initiating position. */
final readonly class CheckoutLineItemSnapshot
{
    public function __construct(
        private string $stripeLineItemId,
        private int $initiatingIndex,
        private string $stripePriceId,
        private string $stripeProductId,
        private int $quantity,
        private ?string $description,
        private Money $price,
        private Money $subtotal,
        private Money $discount,
        private Money $tax,
        private Money $total,
        private CheckoutDiscountsSnapshot $checkoutDiscounts,
    ) {}

    public function stripeLineItemId(): string
    {
        return $this->stripeLineItemId;
    }

    public function initiatingIndex(): int
    {
        return $this->initiatingIndex;
    }

    public function stripePriceId(): string
    {
        return $this->stripePriceId;
    }

    public function stripeProductId(): string
    {
        return $this->stripeProductId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function tax(): Money
    {
        return $this->tax;
    }

    public function total(): Money
    {
        return $this->total;
    }

    /** @return list<DiscountSnapshot> */
    public function discounts(): array
    {
        return $this->checkoutDiscounts->discounts();
    }
}
