<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

/** @internal Authoritative selected-shipping identity, details and total for one Checkout Session. */
final readonly class CheckoutShippingSnapshot
{
    private function __construct(
        private ?string $stripeShippingRateId,
        private ?ShippingSnapshot $shipping,
        private ?string $total,
    ) {}

    public static function unavailable(): self
    {
        // Distinct from none(): Stripe did not return authoritative shipping
        // totals, so consumers must not infer that shipping cost zero.
        return new self(null, null, null);
    }

    public static function none(string $total): self
    {
        // An authoritative total can exist without a selected shipping rate,
        // including an explicit zero returned by Stripe.
        return new self(null, null, $total);
    }

    public static function selected(string $stripeShippingRateId, ShippingSnapshot $shipping): self
    {
        return new self($stripeShippingRateId, $shipping, $shipping->total());
    }

    public function stripeShippingRateId(): ?string
    {
        return $this->stripeShippingRateId;
    }

    public function shipping(): ?ShippingSnapshot
    {
        return $this->shipping;
    }

    public function total(): ?string
    {
        return $this->total;
    }
}
