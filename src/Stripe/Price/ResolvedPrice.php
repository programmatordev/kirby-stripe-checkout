<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use ProgrammatorDev\StripeCheckout\Money\MoneySnapshot;

/**
 * Contains the authoritative, validated Price/Product facts used downstream.
 *
 * @internal
 */
final readonly class ResolvedPrice
{
    /** @param list<string> $images */
    public function __construct(
        private string $priceId,
        private string $productId,
        private string $name,
        private MoneySnapshot $unitPrice,
        private string $taxBehavior,
        private ?string $description = null,
        private array $images = [],
        private ?string $nickname = null,
        private ?string $taxCode = null,
    ) {}

    public function priceId(): string
    {
        return $this->priceId;
    }

    public function productId(): string
    {
        return $this->productId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unitPrice(): MoneySnapshot
    {
        return $this->unitPrice;
    }

    public function taxBehavior(): string
    {
        return $this->taxBehavior;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function images(): array
    {
        return $this->images;
    }

    public function nickname(): ?string
    {
        return $this->nickname;
    }

    public function taxCode(): ?string
    {
        return $this->taxCode;
    }

    /** @return array<string, bool|int|string|list<string>|null> */
    public function toArray(): array
    {
        return [
            'priceId' => $this->priceId,
            'productId' => $this->productId,
            'name' => $this->name,
            'currency' => $this->unitPrice->currency(),
            'minorAmount' => $this->unitPrice->minorAmount(),
            'taxBehavior' => $this->taxBehavior,
            'description' => $this->description,
            'images' => $this->images,
            'nickname' => $this->nickname,
            'taxCode' => $this->taxCode,
        ];
    }
}
