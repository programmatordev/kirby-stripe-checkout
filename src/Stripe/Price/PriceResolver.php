<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use Brick\Math\BigDecimal;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
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

    public function resolve(StripePriceReference|string $reference, string $currency): ResolvedPrice
    {
        $priceId = $reference instanceof StripePriceReference
            ? $reference->priceId()
            : (new StripePriceReference($reference))->priceId();

        try {
            return $this->resolveRecord($this->provider->retrieve($priceId), $currency, $priceId);
        } catch (InvalidProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException('product.stripe_price_unavailable', $error);
        }
    }

    public function resolveRecord(
        PriceRecord $record,
        string $currency,
        ?string $expectedPriceId = null,
    ): ResolvedPrice {
        $currency = strtoupper($currency);

        if (
            ($expectedPriceId !== null && $record->priceId !== $expectedPriceId)
            || preg_match('/^price_[A-Za-z0-9]{1,249}$/D', $record->priceId) !== 1
            || $record->active === false
            || $record->type !== 'one_time'
            || $record->billingScheme !== 'per_unit'
            || $record->hasCustomUnitAmount
            || $record->hasRecurring
            || $record->hasTiers
            || $record->tiersMode !== null
            || $record->hasQuantityTransform
        ) {
            throw new InvalidProductException('product.stripe_price_ineligible');
        }

        $productName = $record->productName;
        $productId = $record->productId;

        if (
            is_string($productId) === false
            || preg_match('/^prod_[A-Za-z0-9]{1,249}$/D', $productId) !== 1
            || $record->productActive === false
            || is_string($productName) === false
        ) {
            throw new InvalidProductException('product.stripe_product_ineligible');
        }

        $providerCurrency = strtoupper($record->currency);

        if ($providerCurrency !== $currency || $this->currencies->supports($providerCurrency) === false) {
            throw new InvalidProductException('product.currency_mismatch');
        }

        $minorAmount = $this->minorAmount($record);

        try {
            $unitPrice = $this->currencies->fromProviderAmount($minorAmount, $providerCurrency);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.price_invalid', $error);
        }

        $taxBehavior = $record->taxBehavior ?? 'unspecified';

        return new ResolvedPrice(
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

    private function minorAmount(PriceRecord $record): int
    {
        try {
            $decimal = $record->unitAmountDecimal;

            if ($decimal !== null) {
                if (preg_match('/^[0-9]+(?:\.0+)?$/D', $decimal) !== 1) {
                    throw new InvalidProductException('product.stripe_price_ineligible');
                }

                $amount = BigDecimal::of($decimal)->toBigInteger()->toInt();

                if ($record->unitAmount !== null && $record->unitAmount !== $amount) {
                    throw new InvalidProductException('product.stripe_price_ineligible');
                }

                return $amount;
            }

            if ($record->unitAmount === null || $record->unitAmount < 0) {
                throw new InvalidProductException('product.stripe_price_ineligible');
            }

            return $record->unitAmount;
        } catch (InvalidProductException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidProductException('product.stripe_price_ineligible', $error);
        }
    }
}
