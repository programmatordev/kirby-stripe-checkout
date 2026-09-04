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
            || $this->validString($productName, 500) === false
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

        if (in_array($taxBehavior, ['exclusive', 'inclusive', 'unspecified'], true) === false) {
            throw new InvalidProductException('product.stripe_price_ineligible');
        }

        return new ResolvedPrice(
            priceId: $record->priceId,
            productId: $productId,
            name: $productName,
            unitPrice: $unitPrice,
            taxBehavior: $taxBehavior,
            description: $this->optionalString($record->productDescription, 5000),
            images: $this->images($record->productImages),
            nickname: $this->optionalString($record->nickname, 500),
            taxCode: $this->optionalString($record->productTaxCode, 500),
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

    /**
     * @param list<string> $images
     * @return list<string>
     */
    private function images(array $images): array
    {
        $valid = [];

        foreach ($images as $image) {
            if (
                $this->validString($image, 2048) === false
                || in_array(parse_url($image, PHP_URL_SCHEME), ['http', 'https'], true) === false
            ) {
                throw new InvalidProductException('product.images_invalid');
            }

            $valid[$image] = true;
        }

        if (count($valid) > 8) {
            throw new InvalidProductException('product.images_invalid');
        }

        return array_keys($valid);
    }

    private function optionalString(?string $value, int $maximum): ?string
    {
        return $value === null || $value === ''
            ? null
            : ($this->validString($value, $maximum) ? $value : throw new InvalidProductException());
    }

    private function validString(?string $value, int $maximum): bool
    {
        return is_string($value)
            && $value !== ''
            && trim($value) === $value
            && strlen($value) <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
