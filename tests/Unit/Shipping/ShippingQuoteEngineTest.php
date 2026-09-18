<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use Brick\Money\Money;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ClosureShippingResolver;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingQuoteEngine;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingZoneResolver;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use RuntimeException;

final class ShippingQuoteEngineTest extends TestCase
{
    public function testExplicitCountryWinsOverFallbackWithoutMergingOptions(): void
    {
        $explicit = self::option('iberia', 'Iberia');
        $fallback = self::option('world', 'Worldwide');
        $engine = new ShippingQuoteEngine(new ShippingZoneResolver([
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT', 'ES'], [$explicit]),
        ]));

        $quote = $engine->quote(self::checkout(), self::shipping(destination: 'PT'));

        $this->assertNotNull($quote);
        $this->assertSame(ShippingQuoteStatus::Available, $quote->status());
        $this->assertSame([$explicit], $quote->options());
    }

    public function testKnownDestinationUsesFallbackOrReportsUnavailable(): void
    {
        $fallback = self::option('world', 'Worldwide');
        $withFallback = new ShippingQuoteEngine(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
        ]));
        $withoutFallback = new ShippingQuoteEngine(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
        ]));

        $fallbackQuote = $withFallback->quote(self::checkout(), self::shipping(destination: 'ES'));
        $unavailableQuote = $withoutFallback->quote(self::checkout(), self::shipping(destination: 'ES'));

        $this->assertNotNull($fallbackQuote);
        $this->assertSame([$fallback], $fallbackQuote->options());
        $this->assertNotNull($unavailableQuote);
        $this->assertSame(ShippingQuoteStatus::Unavailable, $unavailableQuote->status());
    }

    public function testUnknownDestinationOnlyUsesASoleFallbackZone(): void
    {
        $fallback = self::option('world', 'Worldwide');
        $fallbackOnly = new ShippingQuoteEngine(new ShippingZoneResolver([
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
        ]));
        $explicit = new ShippingQuoteEngine(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
        ]));
        $unconfigured = new ShippingQuoteEngine(new ShippingZoneResolver([]));

        $fallbackQuote = $fallbackOnly->quote(self::checkout(), self::shipping(destination: null));
        $requiredQuote = $explicit->quote(self::checkout(), self::shipping(destination: null));
        $unavailableQuote = $unconfigured->quote(self::checkout(), self::shipping(destination: null));

        $this->assertNotNull($fallbackQuote);
        $this->assertSame([$fallback], $fallbackQuote->options());
        $this->assertNotNull($requiredQuote);
        $this->assertSame(ShippingQuoteStatus::DestinationRequired, $requiredQuote->status());
        $this->assertNotNull($unavailableQuote);
        $this->assertSame(ShippingQuoteStatus::Unavailable, $unavailableQuote->status());
    }

    public function testDigitalOnlyContextSkipsTheResolver(): void
    {
        $called = false;
        $resolver = new ClosureShippingResolver(
            static function () use (&$called): ShippingQuote {
                $called = true;

                throw new RuntimeException('Should not run.');
            },
        );
        $engine = new ShippingQuoteEngine($resolver);

        $quote = $engine->quote(self::checkout(requiresShipping: false), self::shipping());

        $this->assertNull($quote);
        $this->assertFalse($called);
    }

    public function testCustomResolverReplacesBuiltInZonesAndRetainsContext(): void
    {
        $receivedCheckout = null;
        $receivedShipping = null;
        $customOption = self::option('carrier', 'Carrier');
        $resolver = new ClosureShippingResolver(
            static function (
                CheckoutContext $checkout,
                ShippingContext $shipping,
            ) use (&$receivedCheckout, &$receivedShipping, $customOption): ShippingQuote {
                $receivedCheckout = $checkout;
                $receivedShipping = $shipping;

                return ShippingQuote::available([$customOption]);
            },
        );
        $checkout = self::checkout();
        $shipping = self::shipping();

        $quote = (new ShippingQuoteEngine($resolver))->quote($checkout, $shipping);

        $this->assertSame($checkout, $receivedCheckout);
        $this->assertSame($shipping, $receivedShipping);
        $this->assertNotNull($quote);
        $this->assertSame([$customOption], $quote->options());
    }

    public function testResolverFailuresAreSanitizedAndCurrencyIsGuarded(): void
    {
        $failed = new ShippingQuoteEngine(new ClosureShippingResolver(
            static fn(): never => throw new RuntimeException('Private carrier response.'),
        ));

        try {
            $failed->quote(self::checkout(), self::shipping());
            self::fail('The resolver failure should be normalized.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::RESOLVER_FAILED, $error->errorCode());
            $this->assertStringNotContainsString('Private carrier response', $error->getMessage());
        }

        $wrongCurrency = new ShippingQuoteEngine(new ClosureShippingResolver(
            static fn(): ShippingQuote => ShippingQuote::available([
                new ShippingOption('standard', 'Standard', Money::of('5.00', 'USD')),
            ]),
        ));

        try {
            $wrongCurrency->quote(self::checkout(), self::shipping());
            self::fail('The resolver currency should match the checkout.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::CURRENCY_MISMATCH, $error->errorCode());
        }
    }

    private static function checkout(
        bool $requiresShipping = true,
    ): CheckoutContext {
        return new CheckoutContext(
            items: [new CheckoutLineItem(
                productReference: 'page://product',
                variantId: null,
                sku: null,
                quantity: 1,
                price: Money::of('20.00', 'EUR'),
                subtotal: Money::of('20.00', 'EUR'),
                requiresShipping: $requiresShipping,
            )],
            languageCode: 'en',
            locale: 'en_US',
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Embedded,
        );
    }

    private static function shipping(?string $destination = 'PT'): ShippingContext
    {
        return new ShippingContext(
            allowedCountries: ['PT', 'ES'],
            destinationCountry: $destination,
        );
    }

    private static function option(
        string $key = 'standard',
        string $label = 'Standard',
    ): ShippingOption {
        return new ShippingOption($key, $label, Money::of('4.90', 'EUR'));
    }

    /**
     * @param list<string> $countries
     * @param list<ShippingOption> $options
     */
    private static function zone(
        string $name,
        ShippingZoneScope $scope,
        array $countries,
        array $options,
    ): ShippingZone {
        return new ShippingZone($name, $scope, $countries, $options);
    }
}
