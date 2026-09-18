<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Kirby;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Kirby\ShippingZoneStructureAdapter;

final class ShippingZoneStructureAdapterTest extends TestCase
{
    public function testCanonicalRowsKeepStableZoneAndOptionIds(): void
    {
        $canonical = (new ShippingZoneStructureAdapter())->canonical(self::fixture());

        $this->assertSame('zone000000000001', $canonical[0]['id']);
        $this->assertSame('option0000000001', $canonical[0]['options'][0]['id']);
    }

    public function testLocalizedRowsAcceptOnlyNestedOptionLabels(): void
    {
        $adapter = new ShippingZoneStructureAdapter();
        $canonical = $adapter->canonical(self::fixture());
        $localized = $adapter->localized($canonical, [[
            'id' => 'zone000000000001',
            'name' => 'Altered zone',
            'scope' => 'fallback',
            'countries' => [],
            'options' => [[
                'id' => 'option0000000001',
                'key' => 'altered',
                'amount' => '0',
                'label' => 'Entrega normal',
            ]],
        ]]);

        $this->assertSame('Iberia', $localized[0]['name']);
        $this->assertSame('selected_countries', $localized[0]['scope']);
        $this->assertSame(['PT', 'ES'], $localized[0]['countries']);
        $this->assertSame('standard', $localized[0]['options'][0]['key']);
        $this->assertSame('4.90', $localized[0]['options'][0]['amount']);
        $this->assertSame('Entrega normal', $localized[0]['options'][0]['label']);
        $this->assertSame('Express delivery', $localized[0]['options'][1]['label']);
    }

    public function testOverlayFollowsCanonicalNestedMembershipAndOrder(): void
    {
        $adapter = new ShippingZoneStructureAdapter();
        $canonical = $adapter->canonical(self::fixture());
        $overlay = $adapter->overlay($canonical, [[
            'id' => 'zone000000000001',
            'options' => [[
                'id' => 'option0000000002',
                'label' => 'Entrega expresso',
            ]],
        ]]);

        $this->assertSame(['zone000000000001'], array_column($overlay, 'id'));
        $this->assertSame(
            ['option0000000001', 'option0000000002'],
            array_column($overlay[0]['options'], 'id'),
        );
        $this->assertSame('', $overlay[0]['options'][0]['label']);
        $this->assertSame('Entrega expresso', $overlay[0]['options'][1]['label']);
    }

    public function testOverlayRejectsInvalidTranslatedLabels(): void
    {
        $adapter = new ShippingZoneStructureAdapter();
        $canonical = $adapter->canonical(self::fixture());

        $this->expectException(InvalidArgumentException::class);
        $adapter->overlay($canonical, [[
            'id' => 'zone000000000001',
            'options' => [[
                'id' => 'option0000000001',
                'label' => "Invalid\nlabel",
            ]],
        ]]);
    }

    /** @return list<array<string, mixed>> */
    private static function fixture(): array
    {
        return [[
            'id' => 'zone000000000001',
            'name' => 'Iberia',
            'scope' => 'selected_countries',
            'countries' => ['PT', 'ES'],
            'options' => [
                [
                    'id' => 'option0000000001',
                    'key' => 'standard',
                    'label' => 'Standard delivery',
                    'amount' => '4.90',
                    'deliveryEstimate' => null,
                ],
                [
                    'id' => 'option0000000002',
                    'key' => 'express',
                    'label' => 'Express delivery',
                    'amount' => '8.90',
                    'deliveryEstimate' => null,
                ],
            ],
        ]];
    }
}
