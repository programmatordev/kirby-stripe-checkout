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
        $options = ProductBlueprint::options($this->kirby);

        $this->assertSame('EUR', $price['after'] ?? null);
        $this->assertSame('text', $price['type'] ?? null);
        $this->assertTrue($price['required'] ?? false);
        $this->assertSame('EUR', $options['currency'] ?? null);
        $this->assertSame(
            'stripe-checkout-options',
            $options['type'] ?? null,
        );
        $stripePrice = ProductBlueprint::stripePrice($this->kirby);
        $this->assertSame('stripe-checkout-price', $stripePrice['type'] ?? null);
        $this->assertTrue($stripePrice['disabled'] ?? false);
        $this->assertTrue($stripePrice['sourceInactive'] ?? false);
    }

    public function testOnlyTheConfiguredPriceSourceIsEditable(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => [
                    'currency' => 'EUR',
                    'defaultRequiresShipping' => false,
                    'priceSource' => 'stripe',
                ],
            ],
        ]);

        $price = ProductBlueprint::price($this->kirby);
        $stripePrice = ProductBlueprint::stripePrice($this->kirby);

        $this->assertSame('text', $price['type'] ?? null);
        $this->assertTrue($price['disabled'] ?? false);
        $this->assertArrayNotHasKey('required', $price);
        $this->assertSame('stripe-checkout-price', $stripePrice['type'] ?? null);
        $this->assertArrayNotHasKey('disabled', $stripePrice);
        $this->assertTrue($stripePrice['required'] ?? false);
    }

    public function testOnlyTheActivePriceFieldShowsAnIncompleteConfigurationWarning(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => ['priceSource' => 'stripe'],
            ],
        ]);

        $price = ProductBlueprint::price($this->kirby);
        $this->assertSame('text', $price['type'] ?? null);
        $this->assertTrue($price['disabled'] ?? false);
        $stripePrice = ProductBlueprint::stripePrice($this->kirby);
        $this->assertSame('info', $stripePrice['type'] ?? null);
        $this->assertSame('warning', $stripePrice['theme'] ?? null);
    }

    public function testProductFieldsAreIndependentBlueprints(): void
    {
        $this->assertSame('text', ProductBlueprint::name($this->kirby)['type'] ?? null);
        $this->assertSame('textarea', ProductBlueprint::description($this->kirby)['type'] ?? null);
        $this->assertSame('files', ProductBlueprint::images($this->kirby)['type'] ?? null);
        $this->assertSame('text', ProductBlueprint::sku($this->kirby)['type'] ?? null);
        $this->assertSame('select', ProductBlueprint::requiresShipping($this->kirby)['type'] ?? null);
        $stripePrice = ProductBlueprint::stripePrice($this->kirby);
        $this->assertSame('stripe-checkout-price', $stripePrice['type'] ?? null);
        $this->assertTrue($stripePrice['disabled'] ?? false);
    }

    public function testIncompleteSettingsOnlyReplaceDependentFieldsWithAWarning(): void
    {
        $price = ProductBlueprint::price($this->kirby);
        $options = ProductBlueprint::options($this->kirby);

        $this->assertSame('info', $price['type'] ?? null);
        $this->assertSame('warning', $price['theme'] ?? null);
        $this->assertSame('info', $options['type'] ?? null);
        $this->assertSame('warning', $options['theme'] ?? null);
        $this->assertSame('textarea', ProductBlueprint::description($this->kirby)['type'] ?? null);
    }

    public function testSettingsPresetsAreCopiedIntoTheOptionsFieldDefinition(): void
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
        $page->update(['optionPresets' => [[
            'label' => 'T-shirt',
            'options' => [[
                'label' => 'Size',
                'values' => ['Small', 'Large'],
            ]],
        ]]]);
        $options = ProductBlueprint::options($this->kirby);

        $this->assertSame([
            [
                'label' => 'T-shirt',
                'options' => [[
                    'label' => 'Size',
                    'values' => ['Small', 'Large'],
                ]],
            ],
        ], $options['presets'] ?? null);
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start($options);
        $this->kirby = $this->environment->app();
    }
}
