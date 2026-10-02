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
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductErrorCode;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductResolverInterface;
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
        private readonly ProductOptionsSchema $schema = new ProductOptionsSchema(),
        private readonly ProductCommerceResolver $commerce = new ProductCommerceResolver(),
    ) {}

    public function resolve(
        ProductRequest $request,
        ProductResolutionContext $context,
    ): Product {
        $page = $this->locator->find($context->site(), $request->reference());
        $fields = $this->configuration->fields();
        $technicalContent = $this->technicalContent($page);
        $displayContent = $this->displayContent($page, $context->languageCode());
        $canonical = $this->optionsDefinition($this->field($technicalContent, $fields->options())->value());
        $localized = $this->localizedOptions(
            $canonical,
            $this->field($displayContent, $fields->options())->value(),
        );
        $variant = $this->matchedVariant($canonical, $request->selectedOptions());
        $resolvedRequest = new ProductRequest(
            $this->locator->canonicalReference($page),
            $request->quantity(),
            $request->selectedOptions(),
        );
        $price = $this->commerce->price($technicalContent, $fields, $variant, $context);
        $shipping = $this->commerce->requiresShipping($technicalContent, $fields, $variant, $context);
        $selectedOptions = $localized->selectedOptions($request->selectedOptions());

        if ($selectedOptions === null) {
            throw new InvalidProductException(ProductErrorCode::SELECTED_OPTIONS_INVALID);
        }

        $imageFiles = $this->imageFiles(
            $displayContent,
            $technicalContent,
            $fields->images(),
        );
        $imageUrls = array_map(
            static fn(File $imageFile): string => $imageFile->url(),
            array_slice($imageFiles, 0, 8),
        );
        $description = $fields->description() === null
            ? null
            : $this->localizedString($displayContent, $technicalContent, $fields->description());
        $name = $this->localizedString($displayContent, $technicalContent, $fields->name());

        if ($canonical->options() === []) {
            $sku = $this->optionalString($this->field($technicalContent, $fields->sku())->value());
        } elseif ($variant !== null) {
            $sku = $variant->sku();
        } else {
            throw new InvalidProductException(ProductErrorCode::VARIANT_INVALID);
        }

        if ($name === null) {
            throw new InvalidProductException(ProductErrorCode::NAME_MISSING);
        }

        return new Product(
            request: $resolvedRequest,
            name: $name,
            requiresShipping: $shipping,
            price: $price,
            selectedOptions: $selectedOptions,
            description: $description,
            imageUrls: $imageUrls,
            sku: $sku,
            metadata: count($imageFiles) > 8 ? ['imagesTruncated' => true] : [],
            variantId: $variant?->id(),
            image: $imageFiles[0] ?? null,
            taxCode: $this->commerce->taxCode($technicalContent, $fields, $variant, $context),
        );
    }

    private function optionsDefinition(mixed $value): ProductOptionsDefinition
    {
        try {
            return $this->schema->canonical($value);
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::OPTIONS_INVALID, $error);
        }
    }

    private function localizedOptions(ProductOptionsDefinition $canonical, mixed $overlay): ProductOptionsDefinition
    {
        try {
            return $this->schema->localized($canonical, $overlay);
        } catch (Throwable $error) {
            throw new InvalidProductException(ProductErrorCode::OPTIONS_INVALID, $error);
        }
    }

    /** @param array<string, string> $selectedOptions */
    private function matchedVariant(
        ProductOptionsDefinition $canonical,
        array $selectedOptions,
    ): ?VariantDefinition {
        if ($canonical->options() === []) {
            if ($selectedOptions !== []) {
                throw new InvalidProductException(ProductErrorCode::SELECTED_OPTIONS_INVALID);
            }

            return null;
        }

        $variant = $canonical->variantFor($selectedOptions);

        if ($variant === null) {
            throw new InvalidProductException(ProductErrorCode::SELECTED_OPTIONS_INVALID);
        }

        if ($variant->enabled() === false) {
            throw new ProductUnavailableException(ProductErrorCode::VARIANT_UNAVAILABLE);
        }

        return $variant;
    }

    /**
     * @param list<string> $fields
     * @return list<File>
     */
    private function imageFiles(Content $display, Content $technical, array $fields): array
    {
        $imagesByUrl = [];

        foreach ($fields as $field) {
            $files = $this->files($display, $field);

            if ($files->isEmpty() && $display !== $technical) {
                $files = $this->files($technical, $field);
            }

            foreach ($files as $file) {
                $url = $file->url();

                if (preg_match('#^https?://#', $url) === 1) {
                    // Preserve the Kirby File so templates retain operations such as crop(); URLs are projected only by the caller.
                    $imagesByUrl[$url] ??= $file;
                }
            }
        }

        return array_values($imagesByUrl);
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
            throw new InvalidProductException(ProductErrorCode::FIELD_INVALID);
        }

        return $field;
    }
}
