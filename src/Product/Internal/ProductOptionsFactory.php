<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Cms\Page;
use Kirby\Content\Content;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductOption;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductOptionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductResolutionContext;
use ProgrammatorDev\StripeCheckout\Product\ProductVariant;
use Throwable;

/**
 * Builds the safe localized storefront projection from a Kirby Page field.
 *
 * @internal
 */
final class ProductOptionsFactory
{
    public function __construct(
        private readonly ProductConfiguration $configuration,
        private readonly ProductResolutionContext $context,
        private readonly VariantSchema $schema = new VariantSchema(),
        private readonly ProductCommerceResolver $commerce = new ProductCommerceResolver(),
    ) {}

    public function forPage(Page $page, string $field): ProductOptions
    {
        $languageCode = $this->context->languageCode();
        $content = $languageCode === null
            ? $page->content()
            : $page->content($languageCode);

        return $this->fromField($this->field($content, $field), $page, $languageCode);
    }

    public function forField(Field $field): ProductOptions
    {
        $page = $field->parent();

        if ($page instanceof Page === false) {
            throw new InvalidProductException('product.field_invalid');
        }

        return $this->fromField($field, $page, $this->context->languageCode());
    }

    private function fromField(Field $field, Page $page, ?string $languageCode): ProductOptions
    {
        $defaultLanguage = $page->kirby()->defaultLanguage();
        $isTranslation = $defaultLanguage !== null
            && $languageCode !== null
            && $defaultLanguage->code() !== $languageCode;
        // Translations may change names, but the default language owns IDs and commerce facts.
        $technical = $isTranslation
            ? $this->technicalContentValue($page, $field->key())
            : $field->value();
        $translated = $isTranslation ? $field->value() : null;
        $technicalContent = $this->technicalContent($page);
        $fields = $this->configuration->fields();

        try {
            $canonical = $this->schema->canonical($technical);
            $localized = $this->schema->localized($canonical, $translated);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.options_invalid', $error);
        }

        $options = array_map(
            static fn(array $option): ProductOption => new ProductOption(
                $option['id'],
                $option['label'],
                array_map(
                    static fn(array $value): ProductOptionValue => new ProductOptionValue(
                        $value['id'],
                        $value['label'],
                    ),
                    $option['values'],
                ),
            ),
            $localized['options'],
        );
        $variants = array_map(
            fn(array $variant): ProductVariant => new ProductVariant(
                $variant['id'],
                $variant['selectedOptions'],
                $variant['enabled'],
                $this->commerce->price($technicalContent, $fields, $variant, $this->context),
                $this->commerce->requiresShipping(
                    $technicalContent,
                    $fields,
                    $variant,
                    $this->context,
                ),
                sku: $variant['sku'],
            ),
            $localized['variants'],
        );

        return new ProductOptions($options, $variants);
    }

    private function technicalContentValue(Page $page, string $field): mixed
    {
        return $this->field($this->technicalContent($page), $field)->value();
    }

    private function technicalContent(Page $page): Content
    {
        $defaultLanguage = $page->kirby()->defaultLanguage();

        return $defaultLanguage === null
            ? $page->content()
            : $page->content($defaultLanguage->code());
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
