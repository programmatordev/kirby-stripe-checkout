<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\ShippingException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;
use Throwable;

/** Enforces the invariants shared by built-in and custom shipping resolvers. */
final readonly class GuardedShippingResolver implements ShippingResolverInterface
{
    public function __construct(
        private ShippingResolverInterface $resolver,
        private ShippingQuoteValidator $validator = new ShippingQuoteValidator(),
    ) {}

    public function resolve(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ShippingQuote {
        try {
            $quote = $this->resolver->resolve($checkout, $shipping);
        } catch (ShippingException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidShippingQuoteException(ShippingErrorCode::RESOLVER_FAILED, $error);
        }

        return $this->validator->validate($quote, $checkout);
    }
}
