<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\App;
use Kirby\Cms\Blueprint;
use Kirby\Plugin\Plugin;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
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

    public function testProductBlueprintsCanBeLoadedByTheirPublicNames(): void
    {
        $this->kirby = $this->kirby->clone([
            'options' => [
                'programmatordev.stripe-checkout' => [
                    'settings' => ['currency' => 'EUR'],
                ],
            ],
        ]);
        $blueprints = [
            'name' => 'text',
            'price' => 'text',
            'stripe-price' => 'stripe-checkout-price',
            'tax-code' => 'stripe-checkout-tax-code',
            'description' => 'textarea',
            'images' => 'files',
            'sku' => 'text',
            'requires-shipping' => 'select',
            'options' => 'stripe-checkout-options',
        ];

        foreach ($blueprints as $name => $type) {
            $blueprint = Blueprint::load('fields/stripe-checkout/' . $name);

            $this->assertSame($type, $blueprint['type'], $name);
        }
    }

    public function testPluginRemainsRegisteredAcrossFreshApplications(): void
    {
        // Kirby's dynamically registered Site methods are not visible to PHPStan.
        /** @phpstan-ignore-next-line method.notFound */
        $this->assertInstanceOf(StripeCheckout::class, $this->kirby->site()->stripeCheckout());

        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start();
        $this->kirby = $this->environment->app();

        /** @phpstan-ignore-next-line method.notFound */
        $this->assertInstanceOf(StripeCheckout::class, $this->kirby->site()->stripeCheckout());
    }
}
