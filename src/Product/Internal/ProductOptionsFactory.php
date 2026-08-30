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
use ProgrammatorDev\StripeCheckout\Product\ProductVariant;
use Throwable;

/**
 * Builds the safe localized storefront projection from one Kirby Page.
 *
 * @internal
 */
final class ProductOptionsFactory
{
    public function __construct(
        private readonly ProductConfiguration $configuration,
        private readonly VariantSchema $schema = new VariantSchema(),
    ) {}

    public function forPage(Page $page, ?string $languageCode): ProductOptions
    {
        $fields = $this->configuration->fields();
        $technical = $this->technicalContentValue($page, $fields['options']);
        $translated = $this->translatedContentValue($page, $fields['options'], $languageCode);

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
            static fn(array $variant): ProductVariant => new ProductVariant(
                $variant['id'],
                $variant['selectedOptions'],
                $variant['enabled'],
            ),
            $localized['variants'],
        );

        return new ProductOptions($options, $variants);
    }

    private function technicalContentValue(Page $page, string $field): mixed
    {
        $defaultLanguage = $page->kirby()->defaultLanguage();
        $content = $defaultLanguage === null
            ? $page->content()
            : $page->content($defaultLanguage->code());

        return $this->field($content, $field)->value();
    }

    private function translatedContentValue(
        Page $page,
        string $field,
        ?string $languageCode,
    ): mixed {
        $defaultLanguage = $page->kirby()->defaultLanguage();

        if ($languageCode === null || $defaultLanguage?->code() === $languageCode) {
            return null;
        }

        return $this->field($page->content($languageCode), $field)->value();
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
