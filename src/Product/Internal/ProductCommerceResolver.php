<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Content\Content;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\InlinePrice;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
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
     * @param array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePriceId: string, requiresShipping: string, options: string} $fields
     * @param array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}|null $variant
     */
    public function price(
        Content $content,
        array $fields,
        ?array $variant,
        ProductResolutionContext $context,
    ): InlinePrice|StripePriceReference {
        if ($context->priceSource() === PriceSource::Stripe) {
            $priceId = $variant['stripePriceId'] ?? null;
            $priceId ??= $this->optionalString($this->field($content, $fields['stripePriceId'])->value());

            if ($priceId === null) {
                throw new InvalidProductException('product.price_missing');
            }

            return new StripePriceReference($priceId);
        }

        $amount = $variant['price'] ?? null;
        $amount ??= $this->optionalString($this->field($content, $fields['price'])->value());
        $currency = $context->settings()->currency();

        if ($amount === null || $currency === null) {
            throw new InvalidProductException('product.price_missing');
        }

        try {
            $snapshot = $this->currencies->fromDecimal($amount, $currency);

            return new InlinePrice($this->currencies->toMoney($snapshot));
        } catch (Throwable $error) {
            throw new InvalidProductException('product.price_invalid', $error);
        }
    }

    /**
     * @param array{name: string, description: ?string, images: list<string>, sku: string, price: string, stripePriceId: string, requiresShipping: string, options: string} $fields
     * @param array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}|null $variant
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
            throw new InvalidProductException('product.shipping_missing');
        }

        return $shipping;
    }

    private function shippingValue(mixed $value): ?bool
    {
        return match ($value) {
            null, '', 'inherit' => null,
            true, 'yes' => true,
            false, 'no' => false,
            default => throw new InvalidProductException('product.shipping_invalid'),
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
            throw new InvalidProductException('product.field_invalid');
        }

        return $field;
    }
}
