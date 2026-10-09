<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;

/**
 * One authorized browser action with the matching facts to pass to the Session creator.
 *
 * @internal
 */
final readonly class CheckoutSubmission
{
    public function __construct(
        private CheckoutSubmissionInput $input,
        private BrowserAttempt $attempt,
        private CheckoutContext $checkout,
        private ShippingContext $shipping,
        private AttemptBinding $binding,
    ) {}

    public function input(): CheckoutSubmissionInput
    {
        return $this->input;
    }

    public function attempt(): BrowserAttempt
    {
        return $this->attempt;
    }

    public function checkout(): CheckoutContext
    {
        return $this->checkout;
    }

    public function shipping(): ShippingContext
    {
        return $this->shipping;
    }

    public function binding(): AttemptBinding
    {
        return $this->binding;
    }
}
