<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use Brick\Money\Money;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use Throwable;

/**
 * Complete resolved line facts shared by shipping and initiating order snapshots.
 * Copies values, never the Product's mutable Kirby File presentation handle.
 */
final readonly class CheckoutLineItem
{
    private string $productReference;

    private ?string $variantId;

    private ?string $sku;

    private int $quantity;

    private string $name;

    private ?string $description;

    /** @var list<string> */
    private array $imageUrls;

    private bool $requiresShipping;

    /** @var list<SelectedOption> */
    private array $options;

    /** @var array<string, bool|int|string> */
    private array $metadata;

    private PriceSource $priceSource;

    private ?string $stripePriceId;

    private ?string $stripeProductId;

    private ?TaxCode $taxCode;

    private Money $price;

    private Money $subtotal;

    public function __construct(Product $product, ?StripePrice $stripePrice = null)
    {
        $productPrice = $product->price();

        if (
            $productPrice instanceof StripePriceReference
                ? $stripePrice === null || $stripePrice->priceId() !== $productPrice->priceId()
                : $stripePrice !== null
        ) {
            throw new InvalidArgumentException('A checkout line item requires its matching resolved price source.');
        }

        $this->price = $productPrice instanceof Price
            ? $productPrice->price()
            : $stripePrice->price();
        $this->quantity = $product->request()->quantity();
        $this->subtotal = $this->price->multipliedBy($this->quantity);

        try {
            $currencies = new StripeCurrencyRegistry();
            $currencies->fromMoney($this->price);
            $currencies->fromMoney($this->subtotal);
        } catch (Throwable $error) {
            throw new InvalidArgumentException('A checkout line item requires exact non-negative money.', previous: $error);
        }

        $this->productReference = $product->request()->reference();
        $this->variantId = $product->variantId();
        $this->sku = $product->sku();
        // Local descriptions remain local even when Stripe owns the amount.
        $this->name = $product->name();
        $this->description = $product->description();
        $this->imageUrls = $product->imageUrls();
        $this->requiresShipping = $product->requiresShipping();
        $this->options = $product->selectedOptions();
        $this->metadata = $product->metadata();
        $this->priceSource = $product->priceSource();
        $this->stripePriceId = $stripePrice?->priceId();
        $this->stripeProductId = $stripePrice?->productId();
        $this->taxCode = $productPrice instanceof Price ? $product->taxCode() : null;
    }

    public function productReference(): string
    {
        return $this->productReference;
    }

    public function variantId(): ?string
    {
        return $this->variantId;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function imageUrls(): array
    {
        return $this->imageUrls;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    /** @return list<SelectedOption> */
    public function options(): array
    {
        return $this->options;
    }

    /** @return array<string, bool|int|string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function priceSource(): PriceSource
    {
        return $this->priceSource;
    }

    public function stripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function stripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function taxCode(): ?TaxCode
    {
        return $this->taxCode;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }
}
