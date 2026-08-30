<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Kirby\Cms\Page;
use Kirby\Content\Content;
use Kirby\Content\Field;
use ProgrammatorDev\StripeCheckout\Configuration\ProductConfiguration;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionGroup;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionVariant;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionView;
use Throwable;

/**
 * Builds the safe localized storefront projection from one Kirby Page.
 *
 * @internal
 */
final class ProductSelectionViewFactory
{
    public function __construct(
        private readonly ProductConfiguration $configuration,
        private readonly VariantSchema $schema = new VariantSchema(),
    ) {}

    public function forPage(Page $page, ?string $languageCode): ProductSelectionView
    {
        $fields = $this->configuration->fields();
        $technical = $this->technicalContentValue($page, $fields['variants']);
        $translated = $this->translatedContentValue($page, $fields['variants'], $languageCode);

        try {
            $canonical = $this->schema->canonical($technical);
            $localized = $this->schema->localized($canonical, $translated);
        } catch (Throwable $error) {
            throw new InvalidProductException('product.variants_invalid', $error);
        }

        $groups = array_map(
            static fn(array $group): ProductSelectionGroup => new ProductSelectionGroup(
                $group['id'],
                $group['label'],
                array_map(
                    static fn(array $value): ProductSelectionValue => new ProductSelectionValue(
                        $value['id'],
                        $value['label'],
                    ),
                    $group['values'],
                ),
            ),
            $localized['groups'],
        );
        $variants = array_map(
            static fn(array $variant): ProductSelectionVariant => new ProductSelectionVariant(
                $variant['id'],
                $variant['choices'],
                $variant['enabled'],
            ),
            $localized['variants'],
        );

        return new ProductSelectionView($groups, $variants);
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
