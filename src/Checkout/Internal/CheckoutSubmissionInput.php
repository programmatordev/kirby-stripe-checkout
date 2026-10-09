<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Parsed browser choices, before product resolution or purchase binding.
 *
 * @internal
 */
final readonly class CheckoutSubmissionInput
{
    /** @param list<ProductRequest> $items Empty for Cart; non-empty for Direct. */
    public function __construct(
        private CheckoutSource $checkoutSource,
        private AttemptToken $attemptToken,
        private ?string $cartRevision,
        private array $items,
        private ?string $shippingCountry,
        private bool $json,
    ) {}

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function attemptToken(): AttemptToken
    {
        return $this->attemptToken;
    }

    public function cartRevision(): ?string
    {
        return $this->cartRevision;
    }

    /** @return list<ProductRequest> */
    public function items(): array
    {
        return $this->items;
    }

    public function shippingCountry(): ?string
    {
        return $this->shippingCountry;
    }

    public function isJson(): bool
    {
        return $this->json;
    }
}
