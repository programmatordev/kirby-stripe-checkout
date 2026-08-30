<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Product;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Product\Internal\VariantMatrix;
use ProgrammatorDev\StripeCheckout\Product\Internal\VariantSchema;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionGroup;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionValue;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionVariant;
use ProgrammatorDev\StripeCheckout\Product\ProductSelectionView;

final class VariantStorageTest extends TestCase
{
    public function testReconcilesTheMatrixWithoutDiscardingExistingCommerceData(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical([
            'groups' => self::fixtureGroups(),
            'variants' => [[
                'id' => 'existingVariant',
                'choices' => ['sizeGroup' => 'smallValue', 'colourGroup' => 'redValue'],
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
        $value = ['groups' => self::fixtureGroups(), 'variants' => []];

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
            'groups' => self::fixtureGroups(),
            'variants' => [[
                'id' => 'freeVariant',
                'choices' => ['colourGroup' => 'redValue', 'sizeGroup' => 'smallValue'],
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
            'groups' => self::fixtureGroups(),
            'variants' => [[
                'id' => 'floatVariant',
                'choices' => ['colourGroup' => 'redValue', 'sizeGroup' => 'smallValue'],
                'enabled' => true,
                'sku' => null,
                'price' => 19.95,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ]);
    }

    public function testRejectsVariantsWithoutSelectionGroups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variants require at least one group.');

        (new VariantSchema())->canonical([
            'groups' => [],
            'variants' => [[
                'id' => 'orphanVariant001',
                'choices' => [],
                'enabled' => true,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ]);
    }

    public function testLabelsAndGroupOrderDoNotReplaceVariantIdentity(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical([
            'groups' => self::fixtureGroups(),
            'variants' => [],
        ]);
        $reorderedGroups = array_reverse(self::fixtureGroups());
        $group = $reorderedGroups[1];
        $reorderedGroups[1] = [
            ...$group,
            'label' => 'Cor',
            'values' => array_reverse($group['values']),
        ];
        $reconciled = $schema->canonical([
            'groups' => $reorderedGroups,
            'variants' => $canonical['variants'],
        ]);
        $before = [];
        $after = [];

        foreach ($canonical['variants'] as $variant) {
            $before[VariantMatrix::choiceKey($variant['choices'])] = $variant['id'];
        }

        foreach ($reconciled['variants'] as $variant) {
            $after[VariantMatrix::choiceKey($variant['choices'])] = $variant['id'];
        }

        ksort($before);
        ksort($after);

        $this->assertSame($before, $after);
    }

    public function testLargeFixtureGeneratesFiftyVariants(): void
    {
        $groups = [
            ['id' => 'colourGroup', 'label' => 'Colour', 'values' => []],
            ['id' => 'sizeGroup', 'label' => 'Size', 'values' => []],
        ];

        for ($index = 1; $index <= 10; $index++) {
            $groups[0]['values'][] = ['id' => 'colour' . $index, 'label' => 'Colour ' . $index];
        }

        for ($index = 1; $index <= 5; $index++) {
            $groups[1]['values'][] = ['id' => 'sizeValue' . $index, 'label' => 'Size ' . $index];
        }

        $this->assertCount(
            50,
            (new VariantSchema())->canonical(['groups' => $groups, 'variants' => []])['variants'],
        );
    }

    public function testTranslatedOverlayCannotReplaceTechnicalData(): void
    {
        $schema = new VariantSchema();
        $canonical = $schema->canonical(['groups' => self::fixtureGroups(), 'variants' => []]);
        $overlay = $schema->overlay($canonical, [
            'groups' => [[
                'id' => 'colourGroup',
                'label' => 'Cor',
                'values' => [[
                    'id' => 'redValue',
                    'label' => 'Vermelho',
                ]],
            ]],
            'variants' => [[
                'id' => 'injectedVariant',
                'choices' => [],
                'price' => '0.01',
            ]],
        ]);
        $localized = $schema->localized($canonical, $overlay);

        $this->assertSame('Cor', $localized['groups'][0]['label']);
        $this->assertSame('Vermelho', $localized['groups'][0]['values'][0]['label']);
        $this->assertSame('Blue', $localized['groups'][0]['values'][1]['label']);
        $this->assertSame($canonical['variants'], $localized['variants']);
    }

    public function testStorefrontRematchesChoicesAndRejectsDisabledOrStaleSelections(): void
    {
        $canonical = [
            'groups' => self::fixtureGroups(),
            'variants' => [[
                'id' => 'disabledVariant',
                'choices' => ['colourGroup' => 'redValue', 'sizeGroup' => 'smallValue'],
                'enabled' => false,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ]],
        ];
        $view = self::selectionView($canonical['variants'][0]);

        $this->assertNull($view->match([
            'sizeGroup' => 'smallValue',
            'colourGroup' => 'redValue',
        ]));
        $this->assertNull($view->match([
            'sizeGroup' => 'staleValue',
            'colourGroup' => 'redValue',
        ]));
        $this->assertNull($view->match([
            'colourGroup' => 'redValue',
        ]));

        $canonical['variants'][0]['enabled'] = true;
        $this->assertSame(
            'disabledVariant',
            self::selectionView($canonical['variants'][0])->match([
                'sizeGroup' => 'smallValue',
                'colourGroup' => 'redValue',
            ])?->id(),
        );
    }

    public function testCanonicalChoiceKeysDoNotDependOnSubmissionOrder(): void
    {
        $this->assertSame(
            VariantMatrix::choiceKey(['colourGroup' => 'redValue', 'sizeGroup' => 'smallValue']),
            VariantMatrix::choiceKey(['sizeGroup' => 'smallValue', 'colourGroup' => 'redValue']),
        );
    }

    public function testStorefrontMatchingRejectsDelimiterCollisions(): void
    {
        $this->assertNull(self::selectionView([
            'id' => 'canonicalVariant',
            'choices' => ['colourGroup' => 'redValue', 'sizeGroup' => 'smallValue'],
            'enabled' => true,
        ])->match([
            'colourGroup' => 'redValue|sizeGroup:smallValue',
        ]));
    }

    /** @param array{id: string, choices: array<string, string>, enabled: bool} $variant */
    private static function selectionView(array $variant): ProductSelectionView
    {
        return new ProductSelectionView(
            [
                new ProductSelectionGroup('colourGroup', 'Colour', [
                    new ProductSelectionValue('redValue', 'Red'),
                    new ProductSelectionValue('blueValue', 'Blue'),
                ]),
                new ProductSelectionGroup('sizeGroup', 'Size', [
                    new ProductSelectionValue('smallValue', 'Small'),
                    new ProductSelectionValue('largeValue', 'Large'),
                ]),
            ],
            [new ProductSelectionVariant($variant['id'], $variant['choices'], $variant['enabled'])],
        );
    }

    /**
     * @return list<array{id: string, label: string, values: list<array{id: string, label: string}>}>
     */
    private static function fixtureGroups(): array
    {
        return [
            [
                'id' => 'colourGroup',
                'label' => 'Colour',
                'values' => [
                    ['id' => 'redValue', 'label' => 'Red'],
                    ['id' => 'blueValue', 'label' => 'Blue'],
                ],
            ],
            [
                'id' => 'sizeGroup',
                'label' => 'Size',
                'values' => [
                    ['id' => 'smallValue', 'label' => 'Small'],
                    ['id' => 'largeValue', 'label' => 'Large'],
                ],
            ],
        ];
    }
}
