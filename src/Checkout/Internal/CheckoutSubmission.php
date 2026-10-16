<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
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

    /** Exact normalized form input for retrying this already accepted action. */
    public function retryInput(): CheckoutSubmissionInput
    {
        $direct = $this->input->checkoutSource() === CheckoutSource::Direct;
        $items = $direct
            ? array_map(static fn(CheckoutLineItem $lineItem): ProductRequest => $lineItem->productRequest(), $this->checkout->items())
            : [];

        return new CheckoutSubmissionInput(
            checkoutSource: $this->input->checkoutSource(),
            attemptToken: $this->input->attemptToken(),
            cartRevision: $this->input->cartRevision(),
            items: $items,
            shippingCountry: $direct ? $this->shipping->shippingCountry() : null,
            json: false,
        );
    }
}
