<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Stripe\Tax\TaxCodeCatalogue;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;

/** Checks classification against one lazy, operation-scoped cached snapshot. @internal */
final class TaxCodeValidator
{
    /** @var array<string, true>|null */
    private ?array $catalogueIds = null;

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

        if ($this->catalogueIds === null) {
            // Built-in and custom resolvers use cached membership, never a
            // caller's confirmation flag. Storefront reads do not refresh.
            $state = $this->catalogue->cached();

            // Empty successful snapshots mean unknown codes, not an outage.
            // Failed refreshes retain the last-good classification snapshot.
            if ($state['refreshedAt'] === null) {
                throw new InvalidProductException(TaxErrorCode::CATALOGUE_UNAVAILABLE);
            }

            $this->catalogueIds = [];

            foreach ($state['items'] as $taxCode) {
                $this->catalogueIds[$taxCode->id()] = true;
            }
        }

        if (isset($this->catalogueIds[$code->id()]) === false) {
            throw new InvalidProductException(TaxErrorCode::CODE_INVALID);
        }
    }
}
