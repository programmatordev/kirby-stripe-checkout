<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;

/** Checks effective local product classification against cached Stripe facts. @internal */
final class TaxCodeValidator
{
    public function __construct(private readonly TaxCodeCatalogue $catalogue) {}

    public function validate(?TaxCode $code, ProductResolutionContext $context): void
    {
        if (
            $context->settings()->automaticTax() === false
            || $context->priceSource() !== PriceSource::Kirby
            || $code === null
        ) {
            return;
        }

        // Both built-in and custom resolvers use cached membership, not a
        // caller-supplied confirmation flag. Storefront reads never refresh.
        $state = $this->catalogue->cached();

        // A successful empty snapshot means an unknown code, not an outage.
        // A failed refresh does not invalidate the retained last-good snapshot.
        if ($state['refreshedAt'] === null) {
            throw new InvalidProductException(TaxErrorCode::CATALOGUE_UNAVAILABLE);
        }

        foreach ($state['items'] as $taxCode) {
            if ($taxCode->id() === $code->id()) {
                return;
            }
        }

        throw new InvalidProductException(TaxErrorCode::CODE_INVALID);
    }
}
