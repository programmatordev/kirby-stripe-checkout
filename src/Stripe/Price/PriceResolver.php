<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use Brick\Math\BigDecimal;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use Stripe\Price;
use Throwable;

/**
 * Retrieves and validates fixed one-time Stripe Prices at the authority boundary.
 *
 * @internal
 */
final class PriceResolver
{
    public function __construct(
        private readonly PriceProviderInterface $provider,
        private readonly StripeCurrencyRegistry $currencies = new StripeCurrencyRegistry(),
    ) {}

    public function resolve(StripePriceReference|string $reference, string $currency): StripePrice
    {
        $priceId = $reference instanceof StripePriceReference
            ? $reference->priceId()
            : (new StripePriceReference($reference))->priceId();

        try {
            return $this->resolveRecord($this->provider->retrieve($priceId), $currency, $priceId);
        } catch (InvalidProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_UNAVAILABLE, $error);
        }
    }

    public function resolveRecord(
        PriceRecord $record,
        string $currency,
        ?string $expectedPriceId = null,
    ): StripePrice {
        $currency = strtoupper($currency);

        // Catalogue entries have no single requested ID; direct retrieval must match the requested reference.
        if ($expectedPriceId !== null && $record->priceId !== $expectedPriceId) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }

        if (preg_match('/^price_[A-Za-z0-9]{1,249}$/D', $record->priceId) !== 1) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }

        // New purchases require active catalogue records; historical order reads use their frozen snapshots instead.
        if ($record->active === false) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }

        $this->assertSupportedPricing($record);

        $productName = $record->productName;
        $productId = $record->productId;

        if (
            is_string($productId) === false
            || preg_match('/^prod_[A-Za-z0-9]{1,249}$/D', $productId) !== 1
        ) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRODUCT_INELIGIBLE);
        }

        if ($record->productActive === false) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRODUCT_INELIGIBLE);
        }

        if (is_string($productName) === false) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRODUCT_INELIGIBLE);
        }

        $providerCurrency = strtoupper($record->currency);

        if ($providerCurrency !== $currency) {
            throw new InvalidProductException(ProductErrorCode::CURRENCY_MISMATCH);
        }

        if ($this->currencies->supports($providerCurrency) === false) {
            throw new InvalidProductException(ProductErrorCode::CURRENCY_MISMATCH);
        }

        $minorAmount = $this->minorAmount($record);

        try {
            $unitPrice = $this->currencies->fromProviderAmount($minorAmount, $providerCurrency);
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::PRICE_INVALID, $error);
        }

        $taxBehavior = $record->taxBehavior ?? Price::TAX_BEHAVIOR_UNSPECIFIED;

        // The domain value owns name, image and optional-text validity; the resolver checks provider presence, correlation and eligibility.
        return new StripePrice(
            priceId: $record->priceId,
            productId: $productId,
            name: $productName,
            unitPrice: $unitPrice,
            taxBehavior: $taxBehavior,
            description: $record->productDescription,
            images: $record->productImages,
            nickname: $record->nickname,
            taxCode: $record->productTaxCode,
        );
    }

    private function assertSupportedPricing(PriceRecord $record): void
    {
        if ($record->type !== Price::TYPE_ONE_TIME) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }

        if ($record->billingScheme !== Price::BILLING_SCHEME_PER_UNIT) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }

        // The enum values alone do not rule out additional pricing features in untrusted provider data.
        if (
            $record->hasCustomUnitAmount
            || $record->hasRecurring
            || $record->hasTiers
            || $record->tiersMode !== null
            || $record->hasQuantityTransform
        ) {
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
        }
    }

    private function minorAmount(PriceRecord $record): int
    {
        try {
            $unitAmountDecimal = $record->unitAmountDecimal;

            // The snapshot requires integer provider units even when the record supplies a decimal string.
            if ($unitAmountDecimal !== null) {
                if (preg_match('/^[0-9]+(?:\.0+)?$/D', $unitAmountDecimal) !== 1) {
                    throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
                }

                $minorAmount = BigDecimal::of($unitAmountDecimal)->toBigInteger()->toInt();

                if ($record->unitAmount !== null && $record->unitAmount !== $minorAmount) {
                    throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
                }

                return $minorAmount;
            }

            if ($record->unitAmount === null || $record->unitAmount < 0) {
                throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE);
            }

            return $record->unitAmount;
        } catch (InvalidProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            // Keep decimal conversion failures classified as ineligible data so resolve() does not report a provider outage.
            throw new InvalidProductException(ProductErrorCode::STRIPE_PRICE_INELIGIBLE, $error);
        }
    }
}
