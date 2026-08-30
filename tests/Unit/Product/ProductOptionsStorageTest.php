<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Product;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Product\Internal\VariantMatrix;
use ProgrammatorDev\StripeCheckout\Product\Internal\VariantSchema;
use ProgrammatorDev\StripeCheckout\Product\ProductOption;
use ProgrammatorDev\StripeCheckout\Product\ProductOptions;
use ProgrammatorDev\StripeCheckout\Product\ProductOptionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductVariant;

final class ProductOptionsStorageTest extends TestCase
{
    public function testReconcilesTheMatrixWithoutDiscardingExistingCommerceData(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical([
            'options' => self::fixtureOptions(),
            'variants' => [[
                'id' => 'existingVariant',
                'selectedOptions' => ['sizeOption' => 'smallValue', 'colourOption' => 'redValue'],
                'enabled' => false,
                'sku' => 'RED-S',
                'price' => '19.95',
                'stripePriceId' => 'price_fixture',
                'requiresShipping' => 'yes',
            ]],
        ]);

        $this->assertCount(4, $canonical['variants']);
        $this->assertSame('existingVariant', $canonical['variants'][0]['id']);
        $this->assertSame('RED-S', $canonical['variants'][0]['sku']);
        $this->assertFalse($canonical['variants'][0]['enabled']);
        $this->assertNotSame('', $canonical['variants'][1]['id']);
    }

    public function testGeneratedIdsRemainStableBeforeTheFieldIsSaved(): void
    {
        $schema = new VariantSchema();
        $value = ['options' => self::fixtureOptions(), 'variants' => []];

        $first = $schema->canonical($value);
        $second = $schema->canonical($value);

        $this->assertSame(
            array_column($first['variants'], 'id'),
            array_column($second['variants'], 'id'),
        );
    }

    public function testPreservesStringZeroAsAVariantPrice(): void
    {
        $canonical = (new VariantSchema())->canonical([
            'options' => self::fixtureOptions(),
            'variants' => [[
                'id' => 'freeVariant',
                'selectedOptions' => ['colourOption' => 'redValue', 'sizeOption' => 'smallValue'],
                'enabled' => true,
                'sku' => null,
                'price' => '0',
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ]);

        $this->assertSame('0', $canonical['variants'][0]['price']);
    }

    public function testRejectsNumericVariantPricesAtTheCanonicalBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant commerce values must be strings.');

        (new VariantSchema())->canonical([
            'options' => self::fixtureOptions(),
            'variants' => [[
                'id' => 'floatVariant',
                'selectedOptions' => ['colourOption' => 'redValue', 'sizeOption' => 'smallValue'],
                'enabled' => true,
                'sku' => null,
                'price' => 19.95,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ]);
    }

    public function testRejectsVariantsWithoutSelectionOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variants require at least one option.');

        (new VariantSchema())->canonical([
            'options' => [],
            'variants' => [[
                'id' => 'orphanVariant001',
                'selectedOptions' => [],
                'enabled' => true,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ]);
    }

    public function testLabelsAndOptionOrderDoNotReplaceVariantIdentity(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical([
            'options' => self::fixtureOptions(),
            'variants' => [],
        ]);
        $reorderedOptions = array_reverse(self::fixtureOptions());
        $option = $reorderedOptions[1];
        $reorderedOptions[1] = [
            ...$option,
            'label' => 'Cor',
            'values' => array_reverse($option['values']),
        ];
        $reconciled = $schema->canonical([
            'options' => $reorderedOptions,
            'variants' => $canonical['variants'],
        ]);
        $before = [];
        $after = [];

        foreach ($canonical['variants'] as $variant) {
            $before[VariantMatrix::optionCombinationKey($variant['selectedOptions'])] = $variant['id'];
        }

        foreach ($reconciled['variants'] as $variant) {
            $after[VariantMatrix::optionCombinationKey($variant['selectedOptions'])] = $variant['id'];
        }

        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    public function testLargeFixtureGeneratesFiftyVariants(): void
    {
        $options = [
            ['id' => 'colourOption', 'label' => 'Colour', 'values' => []],
            ['id' => 'sizeOption', 'label' => 'Size', 'values' => []],
        ];

        for ($index = 1; $index <= 10; $index++) {
            $options[0]['values'][] = ['id' => 'colour' . $index, 'label' => 'Colour ' . $index];
        }

        for ($index = 1; $index <= 5; $index++) {
            $options[1]['values'][] = ['id' => 'sizeValue' . $index, 'label' => 'Size ' . $index];
        }

        $this->assertCount(
            50,
            (new VariantSchema())->canonical(['options' => $options, 'variants' => []])['variants'],
        );
    }

    public function testTranslatedOverlayCannotReplaceTechnicalData(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical(['options' => self::fixtureOptions(), 'variants' => []]);
        $overlay = $schema->overlay($canonical, [
            'options' => [[
                'id' => 'colourOption',
                'label' => 'Cor',
                'values' => [[
                    'id' => 'redValue',
                    'label' => 'Vermelho',
                ]],
            ]],
            'variants' => [[
                'id' => 'injectedVariant',
                'selectedOptions' => [],
                'price' => '0.01',
            ]],
        ]);
        $localized = $schema->localized($canonical, $overlay);

        $this->assertSame('Cor', $localized['options'][0]['label']);
        $this->assertSame('Vermelho', $localized['options'][0]['values'][0]['label']);
        $this->assertSame('Blue', $localized['options'][0]['values'][1]['label']);
        $this->assertSame($canonical['variants'], $localized['variants']);
    }

    public function testStorefrontRematchesSelectedOptionsAndRejectsDisabledOrStaleSelections(): void
    {
        $canonical = [
            'options' => self::fixtureOptions(),
            'variants' => [[
                'id' => 'disabledVariant',
                'selectedOptions' => ['colourOption' => 'redValue', 'sizeOption' => 'smallValue'],
                'enabled' => false,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ];
        $view = self::productOptions($canonical['variants'][0]);

        $this->assertNull($view->matchVariant([
            'sizeOption' => 'smallValue',
            'colourOption' => 'redValue',
        ]));
        $this->assertNull($view->matchVariant([
            'sizeOption' => 'staleValue',
            'colourOption' => 'redValue',
        ]));
        $this->assertNull($view->matchVariant([
            'colourOption' => 'redValue',
        ]));

        $canonical['variants'][0]['enabled'] = true;
        $this->assertSame(
            'disabledVariant',
            self::productOptions($canonical['variants'][0])->matchVariant([
                'sizeOption' => 'smallValue',
                'colourOption' => 'redValue',
            ])?->id(),
        );
    }

    public function testCanonicalSelectedOptionKeysDoNotDependOnSubmissionOrder(): void
    {
        $this->assertSame(
            VariantMatrix::optionCombinationKey(['colourOption' => 'redValue', 'sizeOption' => 'smallValue']),
            VariantMatrix::optionCombinationKey(['sizeOption' => 'smallValue', 'colourOption' => 'redValue']),
        );
    }

    public function testStorefrontMatchingRejectsDelimiterCollisions(): void
    {
        $this->assertNull(self::productOptions([
            'id' => 'canonicalVariant',
            'selectedOptions' => ['colourOption' => 'redValue', 'sizeOption' => 'smallValue'],
            'enabled' => true,
        ])->matchVariant([
            'colourOption' => 'redValue|sizeOption:smallValue',
        ]));
    }

    /** @param array{id: string, selectedOptions: array<string, string>, enabled: bool} $variant */
    private static function productOptions(array $variant): ProductOptions
    {
        return new ProductOptions(
            [
                new ProductOption('colourOption', 'Colour', [
                    new ProductOptionValue('redValue', 'Red'),
                    new ProductOptionValue('blueValue', 'Blue'),
                ]),
                new ProductOption('sizeOption', 'Size', [
                    new ProductOptionValue('smallValue', 'Small'),
                    new ProductOptionValue('largeValue', 'Large'),
                ]),
            ],
            [new ProductVariant($variant['id'], $variant['selectedOptions'], $variant['enabled'])],
        );
    }

    /**
     * @return list<array{id: string, label: string, values: list<array{id: string, label: string}>}>
     */
    private static function fixtureOptions(): array
    {
        return [
            [
                'id' => 'colourOption',
                'label' => 'Colour',
                'values' => [
                    ['id' => 'redValue', 'label' => 'Red'],
                    ['id' => 'blueValue', 'label' => 'Blue'],
                ],
            ],
            [
                'id' => 'sizeOption',
                'label' => 'Size',
                'values' => [
                    ['id' => 'smallValue', 'label' => 'Small'],
                    ['id' => 'largeValue', 'label' => 'Large'],
                ],
            ],
        ];
    }
}
