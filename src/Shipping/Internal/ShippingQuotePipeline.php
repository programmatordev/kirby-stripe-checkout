<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;
use Throwable;

/** Resolves, customizes, and validates one effective quote for a Checkout. */
final readonly class ShippingQuotePipeline
{
    public const FILTER = 'programmatordev.stripe-checkout.shipping.quote';

    public function __construct(
        private App $kirby,
        private ShippingResolverInterface $resolver,
    ) {}

    public function resolve(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ?ShippingQuote {
        if ($checkout->shippableItems() === []) {
            return null;
        }

        try {
            $quote = $this->resolver->resolve($checkout, $shipping);
        } catch (Throwable $error) {
            // Expected customer-facing failures are explicit unavailable quotes;
            // thrown resolver details must never cross this boundary.
            throw new InvalidShippingQuoteException(ShippingErrorCode::RESOLVER_FAILED, $error);
        }

        $this->validate($quote, $checkout);

        try {
            $quote = $this->kirby->apply(self::FILTER, [
                'quote' => $quote,
                'checkout' => $checkout,
                'shipping' => $shipping,
            ], 'quote');
        } catch (Throwable $error) {
            throw new InvalidShippingQuoteException(
                ShippingErrorCode::FILTER_FAILED,
                previous: $error,
            );
        }

        if ($quote instanceof ShippingQuote === false) {
            throw new InvalidShippingQuoteException(ShippingErrorCode::FILTER_INVALID);
        }

        $this->validate($quote, $checkout);

        return $quote;
    }

    private function validate(ShippingQuote $quote, CheckoutContext $checkout): void
    {
        if ($quote->status() !== ShippingQuoteStatus::Available) {
            return;
        }

        $currency = $checkout->currency()->getCurrencyCode();

        foreach ($quote->options() as $option) {
            if ($option->amount()->getCurrency()->getCurrencyCode() !== $currency) {
                throw new InvalidShippingQuoteException(ShippingErrorCode::CURRENCY_MISMATCH);
            }
        }
    }
}
