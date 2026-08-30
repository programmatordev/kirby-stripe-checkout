<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Kirby\ProductBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class ProductBlueprintTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testInlinePriceAndVariantBlueprintsUseEffectiveCurrency(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
            ],
        ]);
        $price = ProductBlueprint::price($this->kirby);
        $variants = ProductBlueprint::variants($this->kirby);

        $this->assertSame('EUR', $price['after'] ?? null);
        $this->assertSame('text', $price['type'] ?? null);
        $this->assertSame('EUR', $variants['currency'] ?? null);
        $this->assertSame(
            'stripe-checkout-variants',
            $variants['type'] ?? null,
        );
    }

    public function testProductFieldsAreIndependentBlueprints(): void
    {
        $this->assertSame('text', ProductBlueprint::name($this->kirby)['type'] ?? null);
        $this->assertSame('textarea', ProductBlueprint::description($this->kirby)['type'] ?? null);
        $this->assertSame('files', ProductBlueprint::images($this->kirby)['type'] ?? null);
        $this->assertSame('text', ProductBlueprint::sku($this->kirby)['type'] ?? null);
        $this->assertSame('select', ProductBlueprint::requiresShipping($this->kirby)['type'] ?? null);
        $this->assertSame('info', ProductBlueprint::stripePrice($this->kirby)['type'] ?? null);
    }

    public function testIncompleteSettingsOnlyReplaceDependentFieldsWithAWarning(): void
    {
        $price = ProductBlueprint::price($this->kirby);
        $variants = ProductBlueprint::variants($this->kirby);

        $this->assertSame('info', $price['type'] ?? null);
        $this->assertSame('warning', $price['theme'] ?? null);
        $this->assertSame('info', $variants['type'] ?? null);
        $this->assertSame('warning', $variants['theme'] ?? null);
        $this->assertSame('textarea', ProductBlueprint::description($this->kirby)['type'] ?? null);
    }

    public function testSettingsPresetsAreCopiedIntoTheVariantFieldDefinition(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                ],
            ],
        ]);
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page->update(['variantPresets' => [[
            'label' => 'T-shirt',
            'groups' => [[
                'label' => 'Size',
                'values' => ['Small', 'Large'],
            ]],
        ]]]);
        $variants = ProductBlueprint::variants($this->kirby);

        $this->assertSame([
            [
                'label' => 'T-shirt',
                'groups' => [[
                    'label' => 'Size',
                    'values' => ['Small', 'Large'],
                ]],
            ],
        ], $variants['presets'] ?? null);
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start($options);
        $this->kirby = $this->environment->app();
    }
}
