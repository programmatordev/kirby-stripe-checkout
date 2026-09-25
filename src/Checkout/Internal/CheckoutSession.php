<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;

/** Validated provider Session used for association and ephemeral presentation. */
final readonly class CheckoutSession
{
    public function __construct(
        private CheckoutSessionAssociation $association,
        private ?string $url,
        private ?string $clientSecret,
    ) {}

    public function association(): CheckoutSessionAssociation
    {
        return $this->association;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function clientSecret(): ?string
    {
        return $this->clientSecret;
    }
}
