<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;

/** Validates quote invariants that depend on the current Checkout. */
final readonly class ShippingQuoteValidator
{
    public function validate(
        ShippingQuote $quote,
        CheckoutContext $checkout,
    ): ShippingQuote {
        if ($quote->status() !== ShippingQuoteStatus::Available) {
            return $quote;
        }

        $currency = $checkout->currency()->getCurrencyCode();

        foreach ($quote->options() as $option) {
            if ($option->amount()->getCurrency()->getCurrencyCode() !== $currency) {
                throw new InvalidShippingQuoteException(ShippingErrorCode::CURRENCY_MISMATCH);
            }
        }

        return $quote;
    }
}
