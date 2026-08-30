<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Cms\File;
use Kirby\Cms\Files;
use Kirby\Cms\Page;
use Kirby\Content\Content;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\Exception\ProductUnavailableException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
use ProgrammatorDev\StripeCheckout\Product\ResolvedProduct;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use Throwable;

/**
 * Resolves published Kirby Pages through the configured content-field map.
 *
 * @internal
 */
final class KirbyPageProductResolver implements ProductResolverInterface
{
    public function __construct(
        private readonly ProductConfiguration $configuration,
        private readonly KirbyPageLocator $locator = new KirbyPageLocator(),
        private readonly VariantSchema $schema = new VariantSchema(),
        private readonly ProductCommerceResolver $commerce = new ProductCommerceResolver(),
    ) {}

    public function resolve(
        ProductRequest $request,
        ProductResolutionContext $context,
    ): ResolvedProduct {
        $page = $this->locator->find($context->site(), $request->reference());
        $fields = $this->configuration->fields();
        $technicalContent = $this->technicalContent($page);
        $displayContent = $this->displayContent($page, $context->languageCode());
        $canonical = $this->optionData($this->field($technicalContent, $fields['options'])->value());
        $localized = $this->localizedVariants(
            $canonical,
            $this->field($displayContent, $fields['options'])->value(),
        );
        $variant = $this->matchedVariant($canonical, $request->selectedOptions());
        $resolvedRequest = new ProductRequest(
            $this->locator->canonicalReference($page),
            $request->quantity(),
            $request->selectedOptions(),
        );
        $price = $this->commerce->price($technicalContent, $fields, $variant, $context);
        $shipping = $this->commerce->requiresShipping($technicalContent, $fields, $variant, $context);
        $selectedOptions = $this->selectedOptions($localized['options'], $request->selectedOptions());
        [$images, $imagesTruncated] = $this->images(
            $displayContent,
            $technicalContent,
            $fields['images'],
        );
        $description = $fields['description'] === null
            ? null
            : $this->localizedString($displayContent, $technicalContent, $fields['description']);
        $name = $this->localizedString($displayContent, $technicalContent, $fields['name']);
        if ($canonical['options'] === []) {
            $sku = $this->optionalString($this->field($technicalContent, $fields['sku'])->value());
        } elseif ($variant !== null) {
            $sku = $variant['sku'];
        } else {
            throw new InvalidProductException('product.variant_invalid');
        }

        if ($name === null) {
            throw new InvalidProductException('product.name_missing');
        }

        return new ResolvedProduct(
            request: $resolvedRequest,
            name: $name,
            requiresShipping: $shipping,
            price: $price,
            selectedOptions: $selectedOptions,
            description: $description,
            imageUrls: $images,
            sku: $sku,
            metadata: $imagesTruncated ? ['imagesTruncated' => true] : [],
            variantId: $variant['id'] ?? null,
        );
    }

    /**
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    private function optionData(mixed $value): array
    {
        try {
            return $this->schema->canonical($value);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.options_invalid', $error);
        }
    }

    /**
     * @param array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @return array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>}
     */
    private function localizedVariants(array $canonical, mixed $overlay): array
    {
        try {
            return $this->schema->localized($canonical, $overlay);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.options_invalid', $error);
        }
    }

    /**
     * @param array{options: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>} $canonical
     * @param array<string, string> $selectedOptions
     * @return array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}|null
     */
    private function matchedVariant(array $canonical, array $selectedOptions): ?array
    {
        if ($canonical['options'] === []) {
            if ($selectedOptions !== []) {
                throw new InvalidProductException('product.selected_options_invalid');
            }

            return null;
        }

        foreach ($canonical['variants'] as $variant) {
            $variantOptions = $variant['selectedOptions'];
            ksort($variantOptions);

            if ($variantOptions === $selectedOptions) {
                if ($variant['enabled'] === false) {
                    throw new ProductUnavailableException('product.variant_unavailable');
                }

                return $variant;
            }
        }

        throw new InvalidProductException('product.selected_options_invalid');
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $options
     * @param array<string, string> $selectedOptions
     * @return list<SelectedOption>
     */
    private function selectedOptions(array $options, array $selectedOptions): array
    {
        $selected = [];

        foreach ($options as $option) {
            $valueId = $selectedOptions[$option['id']] ?? null;
            $value = null;

            foreach ($option['values'] as $candidate) {
                if ($candidate['id'] === $valueId) {
                    $value = $candidate;
                    break;
                }
            }

            if ($value === null) {
                throw new InvalidProductException('product.selected_options_invalid');
            }

            $selected[] = new SelectedOption(
                $option['id'],
                $option['label'],
                $value['id'],
                $value['label'],
            );
        }

        return $selected;
    }

    /**
     * @param list<string> $fields
     * @return array{list<string>, bool}
     */
    private function images(Content $display, Content $technical, array $fields): array
    {
        $urls = [];

        foreach ($fields as $field) {
            $files = $this->files($display, $field);

            if ($files->isEmpty() && $display !== $technical) {
                $files = $this->files($technical, $field);
            }

            foreach ($files as $file) {
                $url = $file->url();

                if (preg_match('#^https?://#', $url) === 1) {
                    $urls[$url] = true;
                }
            }
        }

        $all = array_keys($urls);

        return [array_slice($all, 0, 8), count($all) > 8];
    }

    /** @return Files<File> */
    private function files(Content $content, string $field): Files
    {
        // Kirby registers toFiles() as a core Field method at runtime.
        /** @var Files<File> $files */
        /** @phpstan-ignore-next-line method.notFound */
        $files = $this->field($content, $field)->toFiles();

        return $files;
    }

    private function technicalContent(Page $page): Content
    {
        $defaultLanguage = $page->kirby()->defaultLanguage();

        return $defaultLanguage === null
            ? $page->content()
            : $page->content($defaultLanguage->code());
    }

    private function displayContent(Page $page, ?string $languageCode): Content
    {
        return $languageCode === null ? $page->content() : $page->content($languageCode);
    }

    private function localizedString(Content $display, Content $technical, string $field): ?string
    {
        return $this->optionalString($this->field($display, $field)->value())
            ?? $this->optionalString($this->field($technical, $field)->value());
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
