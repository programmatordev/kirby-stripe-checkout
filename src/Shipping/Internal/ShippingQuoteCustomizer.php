<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use Throwable;

/**
 * Applies the project quote filter and validates the effective chained result.
 * Hook failures are normalized so project exception details never reach a public edge.
 */
final readonly class ShippingQuoteCustomizer
{
    public const FILTER = 'programmatordev.stripe-checkout.shipping.quote';

    public function __construct(
        private App $kirby,
        private ShippingQuoteValidator $validator = new ShippingQuoteValidator(),
    ) {}

    public function customize(
        ShippingQuote $quote,
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ShippingQuote {
        try {
            $customizedQuote = $this->kirby->apply(self::FILTER, [
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

        if ($customizedQuote instanceof ShippingQuote === false) {
            throw new InvalidShippingQuoteException(ShippingErrorCode::FILTER_INVALID);
        }

        return $this->validator->validate($customizedQuote, $checkout);
    }
}
