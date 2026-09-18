<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;

/** Skips digital-only checkouts and validates every resolver-produced quote. */
final readonly class ShippingQuoteEngine
{
    private GuardedShippingResolver $resolver;

    public function __construct(ShippingResolverInterface $resolver)
    {
        $this->resolver = new GuardedShippingResolver($resolver);
    }

    public function quote(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ?ShippingQuote {
        if ($checkout->shippableItems() === []) {
            return null;
        }

        return $this->resolver->resolve($checkout, $shipping);
    }
}
