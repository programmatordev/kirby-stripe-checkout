<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

/**
 * Stores one generated variant before product-level commerce fallbacks resolve.
 *
 * @internal
 */
final readonly class VariantDefinition
{
    /** @param array<string, string> $selectedOptions */
    public function __construct(
        private string $id,
        private array $selectedOptions,
        private bool $enabled,
        private ?string $sku,
        private ?string $price,
        private ?string $stripePriceId,
        private ?bool $shippingOverride,
        private ?string $taxCodeId,
    ) {}

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

    public function price(): ?string
    {
        return $this->price;
    }

    public function stripePriceId(): ?string
    {
        return $this->stripePriceId;
    }

    public function shippingOverride(): ?bool
    {
        // null deliberately inherits the product-level shipping requirement.
        return $this->shippingOverride;
    }

    public function taxCodeId(): ?string
    {
        return $this->taxCodeId;
    }

    /**
     * @return array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string, taxCode: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'selectedOptions' => $this->selectedOptions,
            'enabled' => $this->enabled,
            'sku' => $this->sku,
            'price' => $this->price,
            'stripePriceId' => $this->stripePriceId,
            'requiresShipping' => match ($this->shippingOverride) {
                true => 'yes',
                false => 'no',
                null => 'inherit',
            },
            'taxCode' => $this->taxCodeId,
        ];
    }
}
