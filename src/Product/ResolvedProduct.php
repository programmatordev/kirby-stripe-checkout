<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product;

use Kirby\Cms\File;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Contains trusted product facts and optional request-scoped Kirby image access.
 * Native File objects are presentation handles, never persisted commerce data.
 */
final readonly class ResolvedProduct
{
    private string $name;

    /** @var list<SelectedOption> */
    private array $selectedOptions;

    private ?string $description;

    /** @var list<string> */
    private array $imageUrls;

    private ?string $sku;

    /** @var array<string, bool|int|string> */
    private array $metadata;

    private ?string $variantId;

    /**
     * @param array<mixed> $selectedOptions
     * @param array<mixed> $imageUrls
     * @param array<mixed, mixed> $metadata
     */
    public function __construct(
        private ProductRequest $request,
        string $name,
        private bool $requiresShipping,
        private InlinePrice|StripePriceReference $price,
        array $selectedOptions = [],
        ?string $description = null,
        array $imageUrls = [],
        ?string $sku = null,
        array $metadata = [],
        ?string $variantId = null,
        private ?File $image = null,
    ) {
        $this->name = ProductData::name($name);
        $this->description = ProductData::optionalString($description, 5000);
        $this->sku = ProductData::optionalString($sku, 500);
        $this->variantId = $variantId === null ? null : ProductData::identifier($variantId);
        $this->selectedOptions = $this->validateSelectedOptions($selectedOptions);
        $this->imageUrls = $this->validateImages($imageUrls);
        // Native presentation and URL-only consumers must agree on the first image.
        if ($this->image !== null && $this->image->url() !== ($this->imageUrls[0] ?? null)) {
            throw new InvalidProductException('product.images_invalid');
        }
        $this->metadata = $this->validateMetadata($metadata);
    }

    public function request(): ProductRequest
    {
        return $this->request;
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

    /** @return list<SelectedOption> */
    public function selectedOptions(): array
    {
        return $this->selectedOptions;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /** The original first image for Kirby transforms; external URLs have no File. */
    public function image(): ?File
    {
        return $this->image;
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
     * @param array<mixed> $selectedOptions
     * @return list<SelectedOption>
     */
    private function validateSelectedOptions(array $selectedOptions): array
    {
        $requestedOptions = $this->request->selectedOptions();

        if (($requestedOptions === []) !== ($selectedOptions === [])) {
            throw new InvalidProductException('product.selected_options_invalid');
        }

        $resolved = [];

        foreach ($selectedOptions as $selectedOption) {
            if (
                $selectedOption instanceof SelectedOption === false
                || isset($resolved[$selectedOption->optionId()])
            ) {
                throw new InvalidProductException('product.selected_options_invalid');
            }

            $resolved[$selectedOption->optionId()] = $selectedOption->valueId();
        }

        ksort($resolved);

        if ($resolved !== $requestedOptions) {
            throw new InvalidProductException('product.selected_options_invalid');
        }

        if (($requestedOptions === []) !== ($this->variantId === null)) {
            throw new InvalidProductException('product.variant_invalid');
        }

        /** @var list<SelectedOption> $selectedOptions */
        return $selectedOptions;
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
