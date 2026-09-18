<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;

/** Adapts the PHP configuration Closure to the public resolver contract. */
final readonly class ClosureShippingResolver implements ShippingResolverInterface
{
    /** @var Closure(CheckoutContext, ShippingContext): ShippingQuote */
    private Closure $resolver;

    /** @param Closure(CheckoutContext, ShippingContext): ShippingQuote $resolver */
    public function __construct(Closure $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ShippingQuote {
        return ($this->resolver)($checkout, $shipping);
    }
}
