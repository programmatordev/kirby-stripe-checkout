<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;
use ProgrammatorDev\StripeCheckout\Stripe\Price\ResolvedPrice;

/** Exposes one variant with its effective commerce values. */
final readonly class ProductVariant
{
    private string $id;

    /** @var array<string, string> */
    private array $selectedOptions;

    private ?string $sku;

    /** @param array<mixed, mixed> $selectedOptions */
    public function __construct(
        string $id,
        array $selectedOptions,
        private bool $enabled,
        private InlinePrice|ResolvedPrice $sourcePrice,
        private bool $requiresShipping,
        ?string $sku = null,
    ) {
        $this->id = ProductData::identifier($id);
        $normalized = [];

        foreach ($selectedOptions as $optionId => $valueId) {
            if (is_string($optionId) === false) {
                throw new InvalidProductException('product.options_invalid');
            }

            $normalized[ProductData::identifier($optionId)] = ProductData::identifier($valueId);
        }

        if ($normalized === []) {
            throw new InvalidProductException('product.options_invalid');
        }

        ksort($normalized);
        $this->selectedOptions = $normalized;
        $this->sku = ProductData::optionalString($sku, 500);
    }

    public function id(): string
    {
        return $this->id;
    }

    /** @return array<string, string> */
    public function selectedOptions(): array
    {
        return $this->selectedOptions;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function price(): ?Money
    {
        return $this->sourcePrice instanceof InlinePrice
            ? $this->sourcePrice->unitPrice()
            : null;
    }

    public function stripePrice(): ?ResolvedPrice
    {
        return $this->sourcePrice instanceof ResolvedPrice
            ? $this->sourcePrice
            : null;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    /**
     * @return array{
     *   id: string,
     *   selectedOptions: array<string, string>,
     *   enabled: bool,
     *   sku: ?string,
     *   price: ?array{amount: string, currency: string},
     *   stripePrice: ?array{priceId: string, productId: string, name: string, price: array{amount: string, currency: string}, taxBehavior: string, description: ?string, images: list<string>, nickname: ?string, taxCode: ?string},
     *   requiresShipping: bool
     * }
     */
    public function toArray(): array
    {
        $price = $this->price();
        $stripePrice = $this->stripePrice();

        return [
            'id' => $this->id,
            'selectedOptions' => $this->selectedOptions,
            'enabled' => $this->enabled,
            'sku' => $this->sku,
            'price' => $price === null ? null : [
                'amount' => $price->getAmount()->toString(),
                'currency' => $price->getCurrency()->getCurrencyCode(),
            ],
            'stripePrice' => $stripePrice === null ? null : [
                'priceId' => $stripePrice->priceId(),
                'productId' => $stripePrice->productId(),
                'name' => $stripePrice->name(),
                'price' => [
                    'amount' => $stripePrice->price()->getAmount()->toString(),
                    'currency' => $stripePrice->price()->getCurrency()->getCurrencyCode(),
                ],
                'taxBehavior' => $stripePrice->taxBehavior(),
                'description' => $stripePrice->description(),
                'images' => $stripePrice->images(),
                'nickname' => $stripePrice->nickname(),
                'taxCode' => $stripePrice->taxCode(),
            ],
            'requiresShipping' => $this->requiresShipping,
        ];
    }
}
