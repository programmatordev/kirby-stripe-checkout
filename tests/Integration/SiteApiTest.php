<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class SiteApiTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testSiteMethodProvidesTheDefaultSettingsView(): void
    {
        $plugin = $this->stripeCheckout();
        $setting = $plugin->settings()->setting('priceSource');

        $this->assertSame(PriceSource::Kirby, $plugin->settings()->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::InternalDefault, $setting->source());
        $this->assertFalse($setting->isLocked());
    }

    public function testSiteMethodResolvesNestedPhpSettings(): void
    {
        $this->restart([
            self::PREFIX => [
                'settings' => ['priceSource' => 'stripe'],
            ],
        ]);

        $settings = $this->stripeCheckout()->settings();
        $setting = $settings->setting('priceSource');

        $this->assertSame(PriceSource::Stripe, $settings->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Php, $setting->source());
        $this->assertTrue($setting->isLocked());
    }

    public function testSiteMethodResolvesDottedPhpSettings(): void
    {
        $this->restart([
            self::PREFIX . '.settings.priceSource' => 'stripe',
        ]);

        $this->assertSame(
            PriceSource::Stripe,
            $this->stripeCheckout()->settings()->priceSource(),
        );
    }

    public function testInvalidConfigurationDoesNotPreventKirbyFromBooting(): void
    {
        $this->restart([
            self::PREFIX => ['settings' => ['priceSource' => 'remote']],
        ]);

        $this->assertCount(0, $this->kirby->site()->children());
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('configuration.value_invalid');

        $this->stripeCheckout()->settings();
    }

    public function testFreshApplicationsKeepTheirConfigurationIsolated(): void
    {
        $this->restart([
            self::PREFIX => ['settings' => ['priceSource' => 'stripe']],
        ]);
        $firstSettings = $this->stripeCheckout()->settings();

        $this->restart();
        $secondSettings = $this->stripeCheckout()->settings();

        $this->assertSame(PriceSource::Stripe, $firstSettings->priceSource());
        $this->assertSame(PriceSource::Kirby, $secondSettings->priceSource());
    }

    public function testReadingSettingsDoesNotCreateContent(): void
    {
        $contentRoot = $this->kirby->root('content');

        $this->assertCount(0, $this->kirby->site()->children());
        $this->stripeCheckout()->settings();
        $this->assertCount(0, $this->kirby->site()->children());
        $this->assertSame([], glob($contentRoot . '/*') ?: []);
    }

    /** @param array<string, mixed> $options */
    private function restart(array $options = []): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start($options);
        $this->kirby = $this->environment->app();
    }

    private function stripeCheckout(): StripeCheckout
    {
        // This assertion covers Kirby's dynamically registered Site method.
        /** @phpstan-ignore-next-line method.notFound */
        $plugin = $this->kirby->site()->stripeCheckout();

        $this->assertInstanceOf(StripeCheckout::class, $plugin);

        return $plugin;
    }
}
