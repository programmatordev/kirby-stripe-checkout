<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

/** @internal Carries untrusted Tax Code facts from the provider. */
final readonly class TaxCodeRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
    ) {}
}
