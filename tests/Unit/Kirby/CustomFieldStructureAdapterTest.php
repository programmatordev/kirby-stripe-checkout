<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Kirby;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Kirby\CustomFieldStructureAdapter;

final class CustomFieldStructureAdapterTest extends TestCase
{
    public function testNormalizesCanonicalRowsAndRemovesInternalIdsFromDefinitions(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical(self::canonicalFixture());
        $definitions = $adapter->definitions($canonical);

        $this->assertSame('field00000000001', $canonical[0]['id']);
        $this->assertSame('option0000000001', $canonical[1]['options'][0]['id']);
        $firstDefinition = $definitions[0] ?? null;
        $secondDefinition = $definitions[1] ?? null;
        $this->assertIsArray($firstDefinition);
        $this->assertIsArray($secondDefinition);
        $this->assertArrayNotHasKey('id', $firstDefinition);
        $secondOptions = $secondDefinition['options'] ?? null;
        $this->assertIsArray($secondOptions);
        $firstOption = $secondOptions[0] ?? null;
        $this->assertIsArray($firstOption);
        $this->assertArrayNotHasKey('id', $firstOption);
        $this->assertSame('morning', $secondDefinition['defaultValue'] ?? null);
    }

    public function testLocalizedRowsAcceptOnlyLabelsFromMatchingCanonicalIds(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical(self::canonicalFixture());
        $localized = $adapter->localized($canonical, [
            [
                'id' => 'field00000000002',
                'key' => 'changed',
                'label' => 'Entrega',
                'type' => 'text',
                'options' => [
                    [
                        'id' => 'option0000000002',
                        'value' => 'changed',
                        'label' => '',
                    ],
                    [
                        'id' => 'option0000000001',
                        'value' => 'changed',
                        'label' => 'Manhã',
                    ],
                    [
                        'id' => 'orphan0000000001',
                        'label' => 'Ignored',
                    ],
                ],
            ],
            [
                'id' => 'orphan0000000002',
                'label' => 'Ignored',
            ],
        ]);

        $this->assertSame('delivery', $localized[1]['key']);
        $this->assertSame('dropdown', $localized[1]['type']);
        $this->assertSame('Entrega', $localized[1]['label']);
        $this->assertSame('morning', $localized[1]['options'][0]['value']);
        $this->assertSame('Manhã', $localized[1]['options'][0]['label']);
        $this->assertSame('Afternoon', $localized[1]['options'][1]['label']);
        $this->assertCount(2, $localized[1]['options']);
    }

    public function testOverlayFollowsCanonicalMembershipAndOrder(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical(self::canonicalFixture());
        $overlay = $adapter->overlay($canonical, [
            ['id' => 'field00000000002', 'label' => 'Entrega'],
        ]);

        $this->assertSame(
            ['field00000000001', 'field00000000002'],
            array_column($overlay, 'id'),
        );
        $this->assertSame('', $overlay[0]['label']);
        $this->assertSame('Entrega', $overlay[1]['label']);
        $this->assertCount(2, $overlay[1]['options']);
    }

    public function testCanonicalDeletionRemovesTheMatchingOverlayRows(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical([self::canonicalFixture()[1]]);
        $overlay = $adapter->overlay($canonical, [
            ['id' => 'field00000000001', 'label' => 'Referência'],
            ['id' => 'field00000000002', 'label' => 'Entrega'],
        ]);

        $this->assertSame(['field00000000002'], array_column($overlay, 'id'));
        $this->assertSame('Entrega', $overlay[0]['label']);
    }

    public function testFallbackLabelsAreNotPersistedAsExplicitTranslations(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical(self::canonicalFixture());
        $overlay = $adapter->overlay($canonical, [
            [
                'id' => 'field00000000002',
                'label' => 'Delivery',
                'options' => [
                    [
                        'id' => 'option0000000001',
                        'label' => 'Morning',
                    ],
                ],
            ],
        ]);

        $this->assertSame('', $overlay[1]['label']);
        $this->assertSame('', $overlay[1]['options'][0]['label']);
    }

    public function testCanonicalRowsReceiveIdsWhenCreatedThroughTheApi(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical([[
            'key' => 'reference',
            'label' => 'Reference',
            'type' => 'text',
        ]]);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{16}$/', $canonical[0]['id']);
    }

    public function testRejectsMalformedTranslatedLabels(): void
    {
        $adapter = new CustomFieldStructureAdapter();
        $canonical = $adapter->canonical(self::canonicalFixture());

        $this->expectException(InvalidArgumentException::class);
        $adapter->overlay($canonical, [[
            'id' => 'field00000000001',
            'label' => "Invalid\nlabel",
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private static function canonicalFixture(): array
    {
        return [
            [
                'id' => 'field00000000001',
                'key' => 'reference',
                'label' => 'Reference',
                'type' => 'text',
                'required' => false,
                'minimumLength' => '2',
                'maximumLength' => 20,
                'defaultValue' => null,
                'options' => [],
            ],
            [
                'id' => 'field00000000002',
                'key' => 'delivery',
                'label' => 'Delivery',
                'type' => 'dropdown',
                'required' => true,
                'minimumLength' => null,
                'maximumLength' => null,
                'defaultValue' => 'morning',
                'options' => [
                    [
                        'id' => 'option0000000001',
                        'value' => 'morning',
                        'label' => 'Morning',
                    ],
                    [
                        'id' => 'option0000000002',
                        'value' => 'afternoon',
                        'label' => 'Afternoon',
                    ],
                ],
            ],
        ];
    }
}
