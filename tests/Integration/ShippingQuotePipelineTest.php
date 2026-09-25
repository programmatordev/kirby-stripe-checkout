<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\ShippingException;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ClosureShippingResolver;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingQuotePipeline;
use ProgrammatorDev\StripeCheckout\Shipping\Internal\ShippingZoneResolver;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;
use RuntimeException;

final class ShippingQuotePipelineTest extends KirbyTestCase
{
    public function testExplicitCountryWinsOverFallbackWithoutMergingOptions(): void
    {
        $explicit = self::option('iberia', 'Iberia');
        $fallback = self::option('world', 'Worldwide');
        $pipeline = $this->pipeline(new ShippingZoneResolver([
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT', 'ES'], [$explicit]),
        ]));

        $quote = $pipeline->resolve(self::checkout(), self::shipping(country: 'PT'));

        $this->assertNotNull($quote);
        $this->assertSame(ShippingQuoteStatus::Available, $quote->status());
        $this->assertSame([$explicit], $quote->options());
    }

    public function testKnownShippingCountryUsesFallbackOrReportsUnavailable(): void
    {
        $fallback = self::option('world', 'Worldwide');
        $withFallback = $this->pipeline(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
        ]));
        $withoutFallback = $this->pipeline(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
        ]));

        $fallbackQuote = $withFallback->resolve(self::checkout(), self::shipping(country: 'ES'));
        $unavailableQuote = $withoutFallback->resolve(self::checkout(), self::shipping(country: 'ES'));

        $this->assertNotNull($fallbackQuote);
        $this->assertSame([$fallback], $fallbackQuote->options());
        $this->assertNotNull($unavailableQuote);
        $this->assertSame(ShippingQuoteStatus::Unavailable, $unavailableQuote->status());
    }

    public function testUnknownShippingCountryOnlyUsesASoleFallbackZone(): void
    {
        $fallback = self::option('world', 'Worldwide');
        $fallbackOnly = $this->pipeline(new ShippingZoneResolver([
            self::zone('Fallback', ShippingZoneScope::Fallback, [], [$fallback]),
        ]));
        $explicit = $this->pipeline(new ShippingZoneResolver([
            self::zone('Iberia', ShippingZoneScope::SelectedCountries, ['PT'], [self::option()]),
        ]));
        $unconfigured = $this->pipeline(new ShippingZoneResolver([]));

        $fallbackQuote = $fallbackOnly->resolve(self::checkout(), self::shipping(country: null));
        $requiredQuote = $explicit->resolve(self::checkout(), self::shipping(country: null));
        $unavailableQuote = $unconfigured->resolve(self::checkout(), self::shipping(country: null));

        $this->assertNotNull($fallbackQuote);
        $this->assertSame([$fallback], $fallbackQuote->options());
        $this->assertNotNull($requiredQuote);
        $this->assertSame(ShippingQuoteStatus::CountryRequired, $requiredQuote->status());
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
        $pipeline = $this->pipeline($resolver);

        $quote = $pipeline->resolve(self::checkout(requiresShipping: false), self::shipping());

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

        $quote = $this->pipeline($resolver)->resolve($checkout, $shipping);

        $this->assertSame($checkout, $receivedCheckout);
        $this->assertSame($shipping, $receivedShipping);
        $this->assertNotNull($quote);
        $this->assertSame([$customOption], $quote->options());
    }

    public function testResolverFailuresAreSanitizedAndCurrencyIsGuarded(): void
    {
        $failed = $this->pipeline(new ClosureShippingResolver(
            static fn(): never => throw new RuntimeException('Private carrier response.'),
        ));

        try {
            $failed->resolve(self::checkout(), self::shipping());
            self::fail('The resolver failure should be normalized.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::RESOLVER_FAILED, $error->errorCode());
            $this->assertStringNotContainsString('Private carrier response', $error->getMessage());
        }

        $wrongCurrency = $this->pipeline(new ClosureShippingResolver(
            static fn(): ShippingQuote => ShippingQuote::available([
                new ShippingOption('standard', 'Standard', Money::of('5.00', 'USD')),
            ]),
        ));

        try {
            $wrongCurrency->resolve(self::checkout(), self::shipping());
            self::fail('The resolver currency should match the checkout.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::CURRENCY_MISMATCH, $error->errorCode());
        }
    }

    public function testResolverCannotBypassFailureSanitizationWithAShippingException(): void
    {
        $pipeline = $this->pipeline(new ClosureShippingResolver(
            static fn(): never => throw new ShippingException('shipping.private_carrier_token_123'),
        ));

        try {
            $pipeline->resolve(self::checkout(), self::shipping());
            self::fail('The resolver failure should be normalized.');
        } catch (InvalidShippingQuoteException $error) {
            $this->assertSame(ShippingErrorCode::RESOLVER_FAILED, $error->errorCode());
            $this->assertStringNotContainsString('private_carrier_token_123', $error->getMessage());
        }
    }

    private static function checkout(
        bool $requiresShipping = true,
    ): CheckoutContext {
        return new CheckoutContext(
            items: [new CheckoutLineItem(new Product(
                request: new ProductRequest('page://product', 1, []),
                name: 'Product',
                requiresShipping: $requiresShipping,
                price: new Price(Money::of('20.00', 'EUR')),
            ))],
            languageCode: 'en',
            locale: 'en_US',
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Embedded,
        );
    }

    private function pipeline(ShippingResolverInterface $resolver): ShippingQuotePipeline
    {
        return new ShippingQuotePipeline($this->kirby, $resolver);
    }

    private static function shipping(?string $country = 'PT'): ShippingContext
    {
        return new ShippingContext(
            shippingCountry: $country,
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
