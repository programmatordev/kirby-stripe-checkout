<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Currency;
use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\StripeCheckout;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class SiteApiTest extends KirbyTestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout';

    public function testSiteMethodProvidesTheInitializedSettingsView(): void
    {
        $plugin = $this->stripeCheckout();
        $setting = $plugin->settings()->setting('priceSource');

        $this->assertSame(PriceSource::Kirby, $plugin->settings()->priceSource());
        $this->assertNotNull($setting);
        $this->assertSame(SettingSource::Page, $setting->source());
        $this->assertFalse($setting->isLocked());
        $this->assertNull($plugin->settings()->currency());
        $this->assertNull($plugin->settings()->defaultRequiresShipping());
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

    public function testFormatsExactMoneyAndMajorUnitScalars(): void
    {
        $plugin = $this->stripeCheckout();

        $this->assertSame(
            Money::of('19.95', 'EUR')->formatToLocale('en_US'),
            $plugin->formatMoney(Money::of('19.95', 'EUR'), locale: 'en_US'),
        );
        $this->assertSame(
            Money::of('19.95', 'EUR')->formatToLocale('pt_PT'),
            $plugin->formatMoney('19.95', 'EUR', 'pt_PT'),
        );
        $this->assertSame(
            Money::of(-5, 'USD')->formatToLocale('en_US'),
            $plugin->formatMoney(-5, Currency::of('USD'), 'en_US'),
        );
        $this->assertSame(
            Money::of('1.234', 'KWD')->formatToLocale('en_US'),
            $plugin->formatMoney('1.234', 'KWD', 'en_US'),
        );
    }

    public function testMoneyFormattingUsesTheCurrentKirbyLanguageLocale(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            [
                'code' => 'en',
                'default' => true,
                'locale' => 'en_US',
                'name' => 'English',
            ],
            [
                'code' => 'pt',
                'locale' => 'pt_PT',
                'name' => 'Português',
            ],
        ]);
        $this->kirby = $this->environment->app();
        $this->kirby->setCurrentLanguage('pt');

        $formatted = $this->stripeCheckout()->formatMoney('19.95', 'EUR');

        $this->assertSame(Money::of('19.95', 'EUR')->formatToLocale('pt_PT'), $formatted);
    }

    public function testMoneyFormattingUsesConfiguredAndStableFallbackLocales(): void
    {
        $this->restart(['locale' => 'de_DE']);

        $this->assertSame(
            Money::of('19.95', 'EUR')->formatToLocale('de_DE'),
            $this->stripeCheckout()->formatMoney('19.95', 'EUR'),
        );

        $this->restart(['locale' => null]);

        $this->assertSame(
            Money::of('19.95', 'EUR')->formatToLocale('en_US'),
            $this->stripeCheckout()->formatMoney('19.95', 'EUR'),
        );
    }

    public function testCurrencySymbolsRemainExplicitAndLocaleAware(): void
    {
        $plugin = $this->stripeCheckout();

        $this->assertSame('$', $plugin->currencySymbol('USD', 'en_US'));
        $this->assertSame('€', $plugin->currencySymbol(Currency::of('EUR'), 'pt_PT'));
    }

    public function testMoneyFormattingRejectsAmbiguousArguments(): void
    {
        $plugin = $this->stripeCheckout();

        try {
            $plugin->formatMoney('19.95');
            $this->fail('Expected a scalar without currency to be rejected.');
        } catch (MoneyException $error) {
            $this->assertSame('money.currency_required', $error->errorCode());
        }

        try {
            $plugin->formatMoney(Money::of('19.95', 'EUR'), 'USD');
            $this->fail('Expected a redundant currency to be rejected.');
        } catch (MoneyException $error) {
            $this->assertSame('money.currency_redundant', $error->errorCode());
        }

        try {
            $plugin->formatMoney('19.95', 'EUR', ' invalid ');
            $this->fail('Expected an invalid locale to be rejected.');
        } catch (MoneyException $error) {
            $this->assertSame('money.locale_invalid', $error->errorCode());
        }
    }

    public function testMoneyFormattingRejectsFloatsAtThePublicBoundary(): void
    {
        $this->expectException(\TypeError::class);

        /** @phpstan-ignore-next-line argument.type */
        $this->stripeCheckout()->formatMoney(19.95, 'EUR');
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

    public function testReadingSettingsDoesNotCreateAdditionalContent(): void
    {
        $contentRoot = $this->kirby->root('content');
        $before = glob($contentRoot . '/*') ?: [];

        $this->assertCount(0, $this->kirby->site()->children());
        $this->stripeCheckout()->settings();
        $this->assertCount(0, $this->kirby->site()->children());
        $this->assertSame($before, glob($contentRoot . '/*') ?: []);
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
