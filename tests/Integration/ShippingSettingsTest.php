<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Collator;
use Kirby\Form\Form;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\StripeCheckoutPageStore;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingTaxCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment;

final class ShippingSettingsTest extends KirbyTestCase
{
    public function testNativePageFieldPersistsAndResolvesShippingZones(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page->update([
            'currency' => 'EUR',
            'shippingZones' => [self::selectedZone()],
            'shippingTaxBehavior' => 'exclusive',
            'shippingTaxCode' => 'shipping',
        ]);
        $settings = (new RuntimeFactory($this->kirby))->settings();
        $zone = $settings->shippingZones()[0];
        $option = $zone->options()[0];

        $this->assertSame('Iberia', $zone->name());
        $this->assertSame(ShippingZoneScope::SelectedCountries, $zone->scope());
        $this->assertSame(['PT', 'ES'], $zone->countries());
        $this->assertSame('standard', $option->key());
        $this->assertSame(2, $option->deliveryEstimate()?->minimum());
        $this->assertSame(TaxBehavior::Exclusive, $settings->shippingTaxBehavior());
        $this->assertSame(ShippingTaxCode::Shipping, $settings->shippingTaxCode());
    }

    public function testFieldProvidesLocalizedCountryChoicesAndConditionalTaxFields(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update(['currency' => 'EUR']);
        $fields = Form::for($page)->fields();
        $shippingZones = $fields->field('shippingZones')->toArray();
        $shippingTaxBehavior = $fields->field('shippingTaxBehavior');
        $shippingTaxCode = $fields->field('shippingTaxCode');
        $countryOptions = $shippingZones['countryOptions'] ?? null;

        $this->assertIsArray($countryOptions);
        $this->assertSame('EUR', $shippingZones['currency'] ?? null);
        $countryOptionsByValue = array_column($countryOptions, null, 'value');
        /** @var list<string> $countryNames */
        $countryNames = array_column($countryOptions, 'text');
        $sortedCountryNames = $countryNames;
        (new Collator('en'))->sort($sortedCountryNames, Collator::SORT_STRING);
        $this->assertSame($sortedCountryNames, $countryNames);
        $this->assertArrayHasKey('PT', $countryOptionsByValue);
        $this->assertArrayHasKey('AC', $countryOptionsByValue);
        $this->assertArrayNotHasKey('CU', $countryOptionsByValue);
        $portugal = $countryOptionsByValue['PT'];
        $this->assertIsArray($portugal);
        $portugalText = $portugal['text'];
        $this->assertIsString($portugalText);
        $this->assertSame('Portugal', $portugalText);
        $ascensionIsland = $countryOptionsByValue['AC'];
        $this->assertIsArray($ascensionIsland);
        $this->assertSame('Ascension Island', $ascensionIsland['text']);
        $this->assertFalse($shippingTaxBehavior->isActive());
        $this->assertFalse($shippingTaxCode->isActive());

        $fields = Form::for($page)->fill([
            ...$page->content()->toArray(),
            'automaticTax' => true,
        ])->fields();

        $this->assertTrue($fields->field('shippingTaxBehavior')->isActive());
        $this->assertTrue($fields->field('shippingTaxCode')->isActive());
    }

    public function testPhpConfigurationLocksTheCompleteShippingZoneEditor(): void
    {
        $this->environment->close();
        $this->environment = \ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'settings' => [
                    'currency' => 'EUR',
                    'shippingZones' => [self::fallbackZone()],
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->assertTrue($page->blueprint()->field('shippingZones')['disabled'] ?? false);
        $this->assertFalse($page->blueprint()->field('shippingTaxBehavior')['disabled'] ?? false);
    }

    public function testCustomResolverMakesStoredZonesInactiveWithoutLockingTaxDefaults(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout' => [
                'shipping' => [
                    'resolver' => static fn(
                        CheckoutContext $checkout,
                        ShippingContext $shipping,
                    ): ShippingQuote => ShippingQuote::unavailable(),
                ],
            ],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $shippingZones = $page->blueprint()->field('shippingZones');
        $help = $shippingZones['help'] ?? null;

        $this->assertTrue($shippingZones['disabled'] ?? false);
        $this->assertIsString($help);
        $this->assertStringContainsString(
            'programmatordev.stripe-checkout.shipping.resolver',
            $help,
        );
        $this->assertFalse($page->blueprint()->field('shippingTaxBehavior')['disabled'] ?? false);
        $this->assertFalse($page->blueprint()->field('shippingTaxCode')['disabled'] ?? false);
    }

    public function testSecondaryLanguagesStoreOnlyNestedOptionLabels(): void
    {
        $this->environment->close();
        $this->environment = KirbyTestEnvironment::start(languages: [
            ['code' => 'en', 'default' => true, 'locale' => 'en_US', 'name' => 'English'],
            ['code' => 'pt', 'locale' => 'pt_PT', 'name' => 'Português'],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update(['currency' => 'EUR', 'shippingZones' => [self::selectedZone()]], 'en');
        $defaultValue = Form::for($page, language: 'en')->fields()->field('shippingZones')->toArray()['value'] ?? null;

        $this->assertIsArray($defaultValue);
        $zone = $defaultValue[0] ?? null;
        $this->assertIsArray($zone);
        $options = $zone['options'] ?? null;
        $this->assertIsArray($options);
        $option = $options[0] ?? null;
        $this->assertIsArray($option);
        $zoneId = $zone['id'] ?? null;
        $optionId = $option['id'] ?? null;
        $this->assertIsString($zoneId);
        $this->assertIsString($optionId);

        $page->update(['shippingZones' => [[
            'id' => $zoneId,
            'name' => 'Altered zone',
            'scope' => 'fallback',
            'countries' => [],
            'options' => [[
                'id' => $optionId,
                'key' => 'altered',
                'amount' => '0',
                'label' => 'Entrega normal',
            ]],
        ]]], 'pt');
        $this->kirby->setCurrentLanguage('pt');
        $settings = (new RuntimeFactory($this->kirby))->settings();
        $localizedZone = $settings->shippingZones()[0];

        $this->assertSame('Iberia', $localizedZone->name());
        $this->assertSame(ShippingZoneScope::SelectedCountries, $localizedZone->scope());
        $this->assertSame('standard', $localizedZone->options()[0]->key());
        $this->assertSame('Entrega normal', $localizedZone->options()[0]->label());
    }

    public function testPhpZonesCanUsePageOwnedCurrency(): void
    {
        $this->environment->close();
        $this->environment = \ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestEnvironment::start(options: [
            'programmatordev.stripe-checkout.settings.shippingZones' => [self::fallbackZone()],
        ]);
        $this->kirby = $this->environment->app();
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $page = $page->update(['currency' => 'EUR']);
        $settings = (new RuntimeFactory($this->kirby))->settings();
        $option = $settings->shippingZones()[0]->options()[0];

        $this->assertSame('EUR', $option->amount()->getCurrency()->getCurrencyCode());
        $this->assertTrue($page->blueprint()->field('shippingZones')['disabled'] ?? false);
        $this->assertFalse($page->blueprint()->field('currency')['disabled'] ?? false);
    }

    public function testPageSaveRejectsCountriesAssignedToMultipleZones(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('settings.shippingZones.1.countries.0');
        $page->update(['currency' => 'EUR', 'shippingZones' => [
            self::selectedZone(),
            [
                'name' => 'Portugal again',
                'scope' => 'selected_countries',
                'countries' => ['PT'],
                'options' => [[
                    ...self::option(),
                    'key' => 'express',
                    'label' => 'Express delivery',
                ]],
            ],
        ]]);
    }

    public function testPageSaveRejectsBinaryFloatingPointShippingAmounts(): void
    {
        $page = (new StripeCheckoutPageStore($this->kirby))->initialize();
        $zone = self::selectedZone();
        $option = self::option();
        $option['amount'] = 4.9;
        $zone['options'] = [$option];

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('settings.shippingZones.0.options.0.amount');
        $page->update([
            'currency' => 'EUR',
            'shippingZones' => [$zone],
        ]);
    }

    /** @return array<string, mixed> */
    private static function selectedZone(): array
    {
        return [
            'name' => 'Iberia',
            'scope' => 'selected_countries',
            'countries' => ['PT', 'ES'],
            'options' => [self::option()],
        ];
    }

    /** @return array<string, mixed> */
    private static function fallbackZone(): array
    {
        return [
            'name' => 'Rest of the world',
            'scope' => 'fallback',
            'countries' => [],
            'options' => [self::option()],
        ];
    }

    /** @return array<string, mixed> */
    private static function option(): array
    {
        return [
            'key' => 'standard',
            'label' => 'Standard delivery',
            'amount' => '4.90',
            'deliveryEstimate' => [
                'minimum' => 2,
                'maximum' => 4,
                'unit' => 'business_day',
            ],
        ];
    }
}
