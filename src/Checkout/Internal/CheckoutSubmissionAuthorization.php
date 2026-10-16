<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;

/** @internal Browser-authorized input and its single current Cart snapshot, before commerce resolution. */
final readonly class CheckoutSubmissionAuthorization
{
    public function __construct(
        private CheckoutSubmissionInput $input,
        private BrowserAttempt $attempt,
        private BrowserAttemptContext $context,
        private ?CartSnapshot $cart,
    ) {}

    public function input(): CheckoutSubmissionInput
    {
        return $this->input;
    }

    public function attempt(): BrowserAttempt
    {
        return $this->attempt;
    }

    public function context(): BrowserAttemptContext
    {
        return $this->context;
    }

    public function cart(): ?CartSnapshot
    {
        return $this->cart;
    }
}
