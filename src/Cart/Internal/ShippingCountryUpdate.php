<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

/** @internal Validated HTTP input for one revision-bound Cart shipping-country update. */
final readonly class ShippingCountryUpdate
{
    public function __construct(
        private ?string $shippingCountry,
        private string $revision,
    ) {}

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
    }

    public function revision(): string
    {
        return $this->revision;
    }
}
