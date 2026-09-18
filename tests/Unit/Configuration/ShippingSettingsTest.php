<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\PageSettings;
use ProgrammatorDev\StripeCheckout\Configuration\SettingSource;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingTaxCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class ShippingSettingsTest extends TestCase
{
    private const PREFIX = 'programmatordev.stripe-checkout.settings';

    public function testDefaultsRemainDormantAndDoNotGuessShippingConfiguration(): void
    {
        $settings = (new ConfigurationResolver())->resolve([])->configurationOrFail()->settings();

        $this->assertSame([], $settings->shippingZones());
        $this->assertSame(TaxBehavior::StripeDefault, $settings->shippingTaxBehavior());
        $this->assertSame(ShippingTaxCode::StripeDefault, $settings->shippingTaxCode());
        $this->assertSame(SettingSource::InternalDefault, $settings->setting('shippingZones')?->source());
    }

    public function testResolvesLocalizedFixedOptionsWithinTheirZone(): void
    {
        $settings = (new ConfigurationResolver(languageCode: 'pt'))->resolve([
            self::PREFIX . '.currency' => 'EUR',
            self::PREFIX . '.shippingTaxBehavior' => 'inclusive',
            self::PREFIX . '.shippingTaxCode' => 'shipping',
            self::PREFIX . '.shippingZones' => [[
                'name' => 'Iberia',
                'scope' => 'selected_countries',
                'countries' => ['PT', 'ES'],
                'options' => [[
                    ...self::option('standard', 'Standard delivery'),
                    'labels' => ['pt' => 'Entrega normal'],
                    'deliveryEstimate' => [
                        'minimum' => 2,
                        'maximum' => 4,
                        'unit' => 'business_day',
                    ],
                ]],
            ]],
        ])->configurationOrFail()->settings();
        $zone = $settings->shippingZones()[0];
        $option = $zone->options()[0];

        $this->assertSame('Iberia', $zone->name());
        $this->assertSame(ShippingZoneScope::SelectedCountries, $zone->scope());
        $this->assertSame(['PT', 'ES'], $zone->countries());
        $this->assertSame('Entrega normal', $option->label());
        $this->assertSame('4.90', (string) $option->amount()->getAmount());
        $this->assertSame(DeliveryEstimateUnit::BusinessDay, $option->deliveryEstimate()?->unit());
        $this->assertSame(TaxBehavior::Inclusive, $option->taxBehavior());
        $this->assertSame('txcd_92010001', $option->taxCode());
    }

    public function testPhpZoneListLocksReplaceRatherThanMergePageValues(): void
    {
        $page = new PageSettings(
            currency: 'EUR',
            shippingZones: [self::zone('Page zone', [self::option('page', 'Page delivery')])],
        );
        $settings = (new ConfigurationResolver())->resolve([
            self::PREFIX . '.shippingZones' => [self::zone('PHP zone', [self::option('php', 'PHP delivery')])],
        ], $page)->configurationOrFail()->settings();

        $this->assertSame('PHP zone', $settings->shippingZones()[0]->name());
        $this->assertSame('php', $settings->shippingZones()[0]->options()[0]->key());
        $this->assertTrue($settings->setting('shippingZones')?->isLocked());
        $this->assertSame([self::zone('Page zone', [[
            ...self::option('page', 'Page delivery'),
            'deliveryEstimate' => null,
        ]])], $settings->setting('shippingZones')->shadowedValue());
    }

    public function testPhpZonesCanUseThePageCurrencyWithoutLockingIt(): void
    {
        $page = new PageSettings(currency: 'EUR');
        $settings = (new ConfigurationResolver())->resolve([
            self::PREFIX . '.shippingZones' => [self::zone('Worldwide', [
                self::option('standard', 'Standard delivery'),
            ])],
        ], $page)->configurationOrFail()->settings();

        $option = $settings->shippingZones()[0]->options()[0];
        $this->assertSame('EUR', $option->amount()->getCurrency()->getCurrencyCode());
        $this->assertTrue($settings->setting('shippingZones')?->isLocked());
        $this->assertFalse($settings->setting('currency')?->isLocked());
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('invalidConfiguration')]
    public function testRejectsInvalidConfiguration(array $settings, string $path): void
    {
        $report = (new ConfigurationResolver())->resolve([
            'programmatordev.stripe-checkout' => ['settings' => $settings],
        ]);
        $error = $report->error();

        $this->assertNotNull($error);
        $this->assertSame('configuration.value_invalid', $error->errorCode());
        $this->assertSame($path, $error->path());
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'duplicate country across zones' => [[
            'currency' => 'EUR',
            'shippingZones' => [
                self::selectedZone('Portugal', ['PT'], [self::option('pt', 'Portugal')]),
                self::selectedZone('Iberia', ['PT', 'ES'], [self::option('es', 'Spain')]),
            ],
        ], 'settings.shippingZones.1.countries.0'];
        yield 'multiple fallback zones' => [[
            'currency' => 'EUR',
            'shippingZones' => [
                self::zone('Fallback one', [self::option('one', 'One')]),
                self::zone('Fallback two', [self::option('two', 'Two')]),
            ],
        ], 'settings.shippingZones.1.scope'];
        yield 'selected zone without countries' => [[
            'currency' => 'EUR',
            'shippingZones' => [[
                ...self::zone('Portugal', [self::option('standard', 'Standard')]),
                'scope' => 'selected_countries',
            ]],
        ], 'settings.shippingZones.0.countries'];
        yield 'duplicate option key across zones' => [[
            'currency' => 'EUR',
            'shippingZones' => [
                self::selectedZone('Portugal', ['PT'], [self::option('standard', 'Standard')]),
                self::zone('Fallback', [self::option('standard', 'International')]),
            ],
        ], 'settings.shippingZones.1.options.0.key'];
        yield 'more than five options' => [[
            'currency' => 'EUR',
            'shippingZones' => [self::zone('Fallback', [
                self::option('one', 'One'),
                self::option('two', 'Two'),
                self::option('three', 'Three'),
                self::option('four', 'Four'),
                self::option('five', 'Five'),
                self::option('six', 'Six'),
            ])],
        ], 'settings.shippingZones.0.options'];
        yield 'inexact amount' => [[
            'currency' => 'EUR',
            'shippingZones' => [self::zone('Fallback', [[
                ...self::option('standard', 'Standard'),
                'amount' => '1.001',
            ]])],
        ], 'settings.shippingZones.0.options.0.amount'];
        yield 'unknown tax mode' => [[
            'shippingTaxCode' => 'standard',
        ], 'settings.shippingTaxCode'];
    }

    /**
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private static function zone(string $name, array $options): array
    {
        return [
            'name' => $name,
            'scope' => 'fallback',
            'countries' => [],
            'options' => $options,
        ];
    }

    /**
     * @param list<string> $countries
     * @param list<array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private static function selectedZone(string $name, array $countries, array $options): array
    {
        return [
            'name' => $name,
            'scope' => 'selected_countries',
            'countries' => $countries,
            'options' => $options,
        ];
    }

    /** @return array<string, mixed> */
    private static function option(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'amount' => '4.90',
        ];
    }
}
