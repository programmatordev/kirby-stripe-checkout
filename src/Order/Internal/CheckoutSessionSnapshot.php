<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

/** @internal Authoritative customer, discount, tax and shipping facts returned for one Checkout Session. */
final readonly class CheckoutSessionSnapshot
{
    /**
     * @param list<CustomFieldSnapshot> $customFields
     */
    public function __construct(
        private ?string $stripeCustomerId,
        private ?CustomerSnapshot $customer,
        private ?AddressSnapshot $billingAddress,
        private ?AddressSnapshot $shippingAddress,
        private CheckoutShippingSnapshot $checkoutShipping,
        private array $customFields,
        private ?ConsentSnapshot $consent,
        private CheckoutDiscountsSnapshot $checkoutDiscounts,
        private ?TaxSnapshot $tax,
    ) {}

    public function stripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function customer(): ?CustomerSnapshot
    {
        return $this->customer;
    }

    public function billingAddress(): ?AddressSnapshot
    {
        return $this->billingAddress;
    }

    public function shippingAddress(): ?AddressSnapshot
    {
        return $this->shippingAddress;
    }

    public function stripeShippingRateId(): ?string
    {
        return $this->checkoutShipping->stripeShippingRateId();
    }

    public function shipping(): ?ShippingSnapshot
    {
        return $this->checkoutShipping->shipping();
    }

    public function shippingTotal(): ?string
    {
        return $this->checkoutShipping->total();
    }

    /** @return list<CustomFieldSnapshot> */
    public function customFields(): array
    {
        return $this->customFields;
    }

    public function consent(): ?ConsentSnapshot
    {
        return $this->consent;
    }

    /** @return list<DiscountSnapshot> */
    public function discounts(): array
    {
        return $this->checkoutDiscounts->discounts();
    }

    public function discountTotal(): ?string
    {
        return $this->checkoutDiscounts->total();
    }

    public function tax(): ?TaxSnapshot
    {
        return $this->tax;
    }

    public function taxTotal(): ?string
    {
        return $this->tax?->amount();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stripeCustomerId' => $this->stripeCustomerId,
            'customer' => $this->customer?->toArray(),
            'billingAddress' => $this->billingAddress?->toArray(),
            'shippingAddress' => $this->shippingAddress?->toArray(),
            'stripeShippingRateId' => $this->stripeShippingRateId(),
            'shipping' => $this->shipping()?->toArray(),
            'shippingTotal' => $this->shippingTotal(),
            'customFields' => array_map(
                static fn(CustomFieldSnapshot $customField): array => $customField->toArray(),
                $this->customFields,
            ),
            'consent' => $this->consent?->toArray(),
            'discounts' => array_map(
                static fn(DiscountSnapshot $discount): array => $discount->toArray(),
                $this->discounts(),
            ),
            'discountTotal' => $this->discountTotal(),
            'tax' => $this->tax?->toArray(),
            'taxTotal' => $this->taxTotal(),
        ];
    }
}
