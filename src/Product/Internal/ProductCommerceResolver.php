<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Content\Content;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Tax\TaxErrorCode;
use Throwable;

/**
 * Keeps commerce fallbacks identical for product resolution and storefront projections.
 *
 * @internal
 */
final class ProductCommerceResolver
{
    public function __construct(
        private readonly StripeCurrencyRegistry $currencies = new StripeCurrencyRegistry(),
    ) {}

    /**
     * @param array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePrice: string, taxCode: string, requiresShipping: string, options: string} $fields
     * @param array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string, taxCode: ?string}|null $variant
     */
    public function price(
        Content $content,
        array $fields,
        ?array $variant,
        ProductResolutionContext $context,
    ): Price|StripePriceReference {
        if ($context->priceSource() === PriceSource::Stripe) {
            $priceId = $variant['stripePriceId'] ?? null;
            $priceId ??= $this->optionalString($this->field($content, $fields['stripePrice'])->value());

            if ($priceId === null) {
                throw new InvalidProductException(ProductErrorCode::PRICE_MISSING);
            }

            return new StripePriceReference($priceId);
        }

        $amount = $variant['price'] ?? null;
        $amount ??= $this->optionalString($this->field($content, $fields['price'])->value());
        $currency = $context->settings()->currency();

        if ($amount === null || $currency === null) {
            throw new InvalidProductException(ProductErrorCode::PRICE_MISSING);
        }

        try {
            $snapshot = $this->currencies->fromDecimal($amount, $currency);

            return new Price($this->currencies->toMoney($snapshot));
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::PRICE_INVALID, $error);
        }
    }

    /**
     * @param array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePrice: string, taxCode: string, requiresShipping: string, options: string} $fields
     * @param array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string, taxCode: ?string}|null $variant
     */
    public function requiresShipping(
        Content $content,
        array $fields,
        ?array $variant,
        ProductResolutionContext $context,
    ): bool {
        $shipping = $this->shippingValue($variant['requiresShipping'] ?? null);
        $shipping ??= $this->shippingValue($this->field($content, $fields['requiresShipping'])->value());
        $shipping ??= $context->settings()->defaultRequiresShipping();

        if ($shipping === null) {
            throw new InvalidProductException(ProductErrorCode::SHIPPING_MISSING);
        }

        return $shipping;
    }

    /**
     * @param array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePrice: string, taxCode: string, requiresShipping: string, options: string} $fields
     * @param array{taxCode?: ?string}|null $variant
     */
    public function taxCode(Content $content, array $fields, ?array $variant, ProductResolutionContext $context): ?TaxCode
    {
        // Retained local classification is dormant when Stripe Prices own it.
        if ($context->settings()->automaticTax() === false || $context->priceSource() !== PriceSource::Kirby) {
            return null;
        }

        $value = $variant['taxCode'] ?? $this->field($content, $fields['taxCode'])->value();

        // Omission delegates to Stripe's account preset; never invent a code.
        // https://docs.stripe.com/tax/products-prices-tax-codes-tax-behavior
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_string($value) === false) {
            throw new InvalidProductException(TaxErrorCode::CODE_INVALID);
        }

        return new TaxCode(trim($value));
    }

    private function shippingValue(mixed $value): ?bool
    {
        return match ($value) {
            null, '', 'inherit' => null,
            true, 'yes' => true,
            false, 'no' => false,
            default => throw new InvalidProductException(ProductErrorCode::SHIPPING_INVALID),
        };
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function field(Content $content, string $name): Field
    {
        $field = $content->get($name);

        if ($field instanceof Field === false) {
            throw new InvalidProductException(ProductErrorCode::FIELD_INVALID);
        }

        return $field;
    }
}
