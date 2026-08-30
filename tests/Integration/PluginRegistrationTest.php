<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\App;
use Kirby\Plugin\Plugin;
use ProgrammatorDev\StripeCheckout\Kirby\OptionsField;
use ProgrammatorDev\StripeCheckout\Kirby\ProductBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsBlueprint;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPage;
use ProgrammatorDev\StripeCheckout\Panel\StripeCheckoutArea;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;
use ReflectionProperty;

final class PluginRegistrationTest extends KirbyTestCase
{
    public function testRegistersCanonicalPluginMetadata(): void
    {
        $plugin = App::plugin('programmatordev/stripe-checkout');

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertSame(dirname(__DIR__, 2), $plugin->root());

        $declaredVersion = new ReflectionProperty($plugin, 'version');

        $this->assertSame('0.7.0', $declaredVersion->getValue($plugin));
    }

    public function testRegistersTheFoundationExtensions(): void
    {
        $plugin = App::plugin('programmatordev/stripe-checkout');

        $this->assertInstanceOf(Plugin::class, $plugin);
        $extensions = $plugin->extends();
        $blueprints = $extensions['blueprints'] ?? null;
        $pageModels = $extensions['pageModels'] ?? null;
        $fields = $extensions['fields'] ?? null;
        $siteMethods = $extensions['siteMethods'] ?? null;
        $areas = $extensions['areas'] ?? null;
        $translations = $extensions['translations'] ?? null;

        $this->assertSame([], $extensions['options']);
        $this->assertIsArray($blueprints);
        $this->assertSame(
            [SettingsBlueprint::class, 'load'],
            $blueprints['pages/stripe-checkout'],
        );
        $productBlueprints = [
            'fields/stripe-checkout/name' => [ProductBlueprint::class, 'name'],
            'fields/stripe-checkout/price' => [ProductBlueprint::class, 'price'],
            'fields/stripe-checkout/stripe-price' => [ProductBlueprint::class, 'stripePrice'],
            'fields/stripe-checkout/description' => [ProductBlueprint::class, 'description'],
            'fields/stripe-checkout/images' => [ProductBlueprint::class, 'images'],
            'fields/stripe-checkout/sku' => [ProductBlueprint::class, 'sku'],
            'fields/stripe-checkout/requires-shipping' => [ProductBlueprint::class, 'requiresShipping'],
            'fields/stripe-checkout/options' => [ProductBlueprint::class, 'options'],
        ];

        foreach ($productBlueprints as $name => $definition) {
            $this->assertSame($definition, $blueprints[$name] ?? null);
        }
        $this->assertIsArray($pageModels);
        $this->assertSame(StripeCheckoutPage::class, $pageModels['stripe-checkout']);
        $this->assertIsArray($fields);
        $this->assertSame(OptionsField::class, $fields['stripe-checkout-options']);
        $this->assertIsArray($siteMethods);
        $this->assertSame(['stripeCheckout'], array_keys($siteMethods));
        $this->assertIsCallable($siteMethods['stripeCheckout']);
        $this->assertIsArray($areas);
        $this->assertSame(
            [StripeCheckoutArea::class, 'definition'],
            $areas['stripe-checkout'],
        );
        $this->assertSame([
            'settings.read' => false,
            'settings.update' => false,
            'diagnostics.read' => false,
        ], $extensions['permissions']);
        $this->assertIsArray($translations);
        $this->assertSame(['en', 'pt_PT'], array_keys($translations));
        $this->assertSame([], $this->kirby->option('programmatordev.stripe-checkout'));
    }

    public function testDoesNotExposeTheLegacyGlobalHelpers(): void
    {
        $this->assertFalse(function_exists('cart'));
        $this->assertFalse(function_exists('stripeCheckout'));
    }

    public function testShipsTheCompiledPanelFieldAssets(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root . '/index.js');
        $this->assertFileExists($root . '/index.css');
    }

    public function testPluginRemainsRegisteredAcrossFreshApplications(): void
    {
        $firstPlugin = App::plugin('programmatordev/stripe-checkout');

        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start();
        $this->kirby = $this->environment->app();

        $secondPlugin = App::plugin('programmatordev/stripe-checkout');

        $this->assertInstanceOf(Plugin::class, $secondPlugin);
        $this->assertNotSame($firstPlugin, $secondPlugin);
    }
}
