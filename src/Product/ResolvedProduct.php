<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Contains the trusted product snapshot produced by one resolver operation.
 */
final readonly class ResolvedProduct
{
    private string $name;

    /** @var list<SelectedChoice> */
    private array $choices;

    private ?string $description;

    /** @var list<string> */
    private array $imageUrls;

    private ?string $sku;

    /** @var array<string, bool|int|string> */
    private array $metadata;

    private ?string $variantId;

    /**
     * @param array<mixed> $choices
     * @param array<mixed> $imageUrls
     * @param array<mixed, mixed> $metadata
     */
    public function __construct(
        private ProductSelection $selection,
        string $name,
        private bool $requiresShipping,
        private InlinePrice|StripePriceReference $price,
        array $choices = [],
        ?string $description = null,
        array $imageUrls = [],
        ?string $sku = null,
        array $metadata = [],
        ?string $variantId = null,
    ) {
        $this->name = ProductData::label($name);
        $this->description = ProductData::optionalString($description, 5000);
        $this->sku = ProductData::optionalString($sku, 500);
        $this->variantId = $variantId === null ? null : ProductData::identifier($variantId);
        $this->choices = $this->validateChoices($choices);
        $this->imageUrls = $this->validateImages($imageUrls);
        $this->metadata = $this->validateMetadata($metadata);
    }

    public function selection(): ProductSelection
    {
        return $this->selection;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    public function price(): InlinePrice|StripePriceReference
    {
        return $this->price;
    }

    public function priceSource(): PriceSource
    {
        return $this->price instanceof InlinePrice ? PriceSource::Kirby : PriceSource::Stripe;
    }

    /** @return list<SelectedChoice> */
    public function choices(): array
    {
        return $this->choices;
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

    public function sku(): ?string
    {
        return $this->sku;
    }

    /** @return array<string, bool|int|string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function variantId(): ?string
    {
        return $this->variantId;
    }

    /**
     * @param array<mixed> $choices
     * @return list<SelectedChoice>
     */
    private function validateChoices(array $choices): array
    {
        $selectionChoices = $this->selection->choices();

        if (($selectionChoices === []) !== ($choices === [])) {
            throw new InvalidProductException('product.choices_invalid');
        }

        $resolved = [];

        foreach ($choices as $choice) {
            if ($choice instanceof SelectedChoice === false || isset($resolved[$choice->groupId()])) {
                throw new InvalidProductException('product.choices_invalid');
            }

            $resolved[$choice->groupId()] = $choice->valueId();
        }

        ksort($resolved);

        if ($resolved !== $selectionChoices) {
            throw new InvalidProductException('product.choices_invalid');
        }

        if (($selectionChoices === []) !== ($this->variantId === null)) {
            throw new InvalidProductException('product.variant_invalid');
        }

        /** @var list<SelectedChoice> $choices */
        return $choices;
    }

    /**
     * @param array<mixed> $imageUrls
     * @return list<string>
     */
    private function validateImages(array $imageUrls): array
    {
        if (count($imageUrls) > 8 || array_is_list($imageUrls) === false) {
            throw new InvalidProductException('product.images_invalid');
        }

        $normalized = [];

        foreach ($imageUrls as $url) {
            $url = ProductData::requiredString($url, 2048);
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (in_array($scheme, ['http', 'https'], true) === false || isset($normalized[$url])) {
                throw new InvalidProductException('product.images_invalid');
            }

            $normalized[$url] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param array<mixed, mixed> $metadata
     * @return array<string, bool|int|string>
     */
    private function validateMetadata(array $metadata): array
    {
        if (count($metadata) > 20) {
            throw new InvalidProductException('product.metadata_invalid');
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) === false || is_bool($value) === false && is_int($value) === false && is_string($value) === false) {
                throw new InvalidProductException('product.metadata_invalid');
            }

            $normalized[ProductData::identifier($key)] = is_string($value)
                ? ProductData::requiredString($value, 500)
                : $value;
        }

        ksort($normalized);

        return $normalized;
    }
}
