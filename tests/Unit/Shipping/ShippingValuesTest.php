<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use Brick\Money\Money;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingTaxCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Shipping\StripeDestinationCountryRegistry;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class ShippingValuesTest extends TestCase
{
    public function testExposesAValidatedZoneWithFixedShippingOptions(): void
    {
        $option = new ShippingOption(
            key: 'standard',
            label: 'Standard delivery',
            amount: Money::of('4.90', 'EUR'),
            deliveryEstimate: new DeliveryEstimate(
                minimum: 2,
                maximum: 4,
                unit: DeliveryEstimateUnit::BusinessDay,
            ),
            taxBehavior: TaxBehavior::Inclusive,
            taxCode: ShippingTaxCode::Shipping->taxCode(),
        );
        $zone = new ShippingZone(
            name: 'Iberia',
            scope: ShippingZoneScope::SelectedCountries,
            countries: ['PT', 'ES'],
            options: [$option],
        );
        $estimate = $option->deliveryEstimate();

        $this->assertNotNull($estimate);
        $this->assertSame('Iberia', $zone->name());
        $this->assertSame(ShippingZoneScope::SelectedCountries, $zone->scope());
        $this->assertSame(['PT', 'ES'], $zone->countries());
        $this->assertSame([$option], $zone->options());
        $this->assertSame('standard', $option->key());
        $this->assertSame('Standard delivery', $option->label());
        $this->assertSame('4.90', (string) $option->amount()->getAmount());
        $this->assertSame(2, $estimate->minimum());
        $this->assertSame(DeliveryEstimateUnit::BusinessDay, $estimate->unit());
        $this->assertSame(TaxBehavior::Inclusive, $option->taxBehavior());
        $this->assertSame('txcd_92010001', $option->taxCode());
    }

    public function testShippingTaxChoicesMapOnlyTheExplicitCodes(): void
    {
        $this->assertNull(ShippingTaxCode::StripeDefault->taxCode());
        $this->assertSame('txcd_92010001', ShippingTaxCode::Shipping->taxCode());
        $this->assertSame('txcd_00000000', ShippingTaxCode::Nontaxable->taxCode());
    }

    public function testDestinationCountriesFollowTheCheckoutEnum(): void
    {
        $countries = new StripeDestinationCountryRegistry();

        $this->assertTrue($countries->supports('PT'));
        $this->assertTrue($countries->supports('AC'));
        $this->assertTrue($countries->supports('ZZ'));
        $this->assertFalse($countries->supports('CU'));
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(Closure $create): void
    {
        $this->expectException(InvalidArgumentException::class);

        $create();
    }

    /** @return iterable<string, array{Closure(): object}> */
    public static function invalidValues(): iterable
    {
        yield 'blank key' => [static fn(): ShippingOption => self::option(key: '')];
        yield 'oversized key' => [static fn(): ShippingOption => self::option(key: str_repeat('a', 65))];
        yield 'blank label' => [static fn(): ShippingOption => self::option(label: '')];
        yield 'multiline label' => [static fn(): ShippingOption => self::option(label: "Standard\nDelivery")];
        yield 'negative amount' => [static fn(): ShippingOption => self::option(amount: '-1')];
        yield 'invalid tax code' => [static fn(): ShippingOption => new ShippingOption(
            key: 'standard',
            label: 'Standard',
            amount: Money::of('1', 'EUR'),
            taxCode: 'shipping',
        )];
        yield 'blank zone name' => [static fn(): ShippingZone => self::zone(name: '')];
        yield 'fallback with countries' => [static fn(): ShippingZone => self::zone(countries: ['PT'])];
        yield 'selected zone without countries' => [static fn(): ShippingZone => self::zone(
            scope: ShippingZoneScope::SelectedCountries,
        )];
        yield 'duplicate country' => [static fn(): ShippingZone => self::zone(
            scope: ShippingZoneScope::SelectedCountries,
            countries: ['PT', 'PT'],
        )];
        yield 'country unsupported by Checkout' => [static fn(): ShippingZone => self::zone(
            scope: ShippingZoneScope::SelectedCountries,
            countries: ['CU'],
        )];
        yield 'zone without options' => [static fn(): ShippingZone => self::zone(options: [])];
        yield 'duplicate option key' => [static fn(): ShippingZone => self::zone(options: [
            self::option(),
            self::option(label: 'Express'),
        ])];
        yield 'empty estimate' => [static fn(): DeliveryEstimate => new DeliveryEstimate(
            minimum: null,
            maximum: null,
            unit: DeliveryEstimateUnit::Day,
        )];
        yield 'reversed estimate' => [static fn(): DeliveryEstimate => new DeliveryEstimate(
            minimum: 5,
            maximum: 2,
            unit: DeliveryEstimateUnit::Day,
        )];
    }

    private static function option(
        string $key = 'standard',
        string $label = 'Standard',
        string $amount = '4.90',
    ): ShippingOption {
        return new ShippingOption(
            key: $key,
            label: $label,
            amount: Money::of($amount, 'EUR'),
        );
    }

    /**
     * @param list<string> $countries
     * @param list<ShippingOption>|null $options
     */
    private static function zone(
        string $name = 'Fallback',
        ShippingZoneScope $scope = ShippingZoneScope::Fallback,
        array $countries = [],
        ?array $options = null,
    ): ShippingZone {
        return new ShippingZone(
            name: $name,
            scope: $scope,
            countries: $countries,
            options: $options ?? [self::option()],
        );
    }
}
