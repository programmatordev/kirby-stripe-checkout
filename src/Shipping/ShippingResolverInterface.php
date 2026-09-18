<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;

/** Resolves the complete ordered shipping quote for one checkout and shipping context. */
interface ShippingResolverInterface
{
    public function resolve(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ShippingQuote;
}
