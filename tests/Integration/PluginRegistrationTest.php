<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Kirby\Cms\App;
use Kirby\Plugin\Plugin;
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

    public function testRegistersTheOptionRootAndSiteEntryPoint(): void
    {
        $plugin = App::plugin('programmatordev/stripe-checkout');

        $this->assertInstanceOf(Plugin::class, $plugin);
        $extensions = $plugin->extends();
        $siteMethods = $extensions['siteMethods'] ?? null;

        $this->assertSame([], $extensions['options']);
        $this->assertIsArray($siteMethods);
        $this->assertSame(['stripeCheckout'], array_keys($siteMethods));
        $this->assertIsCallable($siteMethods['stripeCheckout']);
        $this->assertSame([], $this->kirby->option('programmatordev.stripe-checkout'));
    }

    public function testDoesNotExposeTheLegacyGlobalHelpers(): void
    {
        $this->assertFalse(function_exists('cart'));
        $this->assertFalse(function_exists('stripeCheckout'));
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
