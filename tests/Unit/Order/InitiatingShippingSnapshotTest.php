<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\StripeShippingCountryRegistry;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class InitiatingShippingSnapshotTest extends TestCase
{
    public function testFreezesCountryPolicyLocalizedOptionsAndProviderAmounts(): void
    {
        $snapshot = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );
        $option = $snapshot->options()[0];

        $this->assertSame(['PT'], $snapshot->allowedCountries());
        $this->assertSame('EUR', $snapshot->currency());
        $this->assertCount(1, $snapshot->options());
        $this->assertSame('Expresso', $option->label());
        $this->assertSame('5.00', (string) $option->amount()->getAmount());
        $this->assertSame(500, (new StripeCurrencyRegistry())->fromMoney($option->amount())->minorAmount());
        $this->assertSame(TaxBehavior::Inclusive, $option->taxBehavior());
        $this->assertSame('txcd_92010001', $option->taxCode());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $snapshot->quoteFingerprint());
    }

    public function testCountrylessAvailableQuoteFreezesAllCountriesPolicy(): void
    {
        $snapshot = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext(null),
            quote: $this->quote(),
        );

        $this->assertSame(
            (new StripeShippingCountryRegistry())->codes(),
            $snapshot->allowedCountries(),
        );
    }

    public function testQuoteFingerprintRetainsResolverLocaleCorrelation(): void
    {
        $portuguese = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );
        $english = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(locale: 'en_US'),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );

        $this->assertNotSame(
            $portuguese->quoteFingerprint(),
            $english->quoteFingerprint(),
        );
    }

    public function testRejectsNonAvailableQuoteAndDigitalContext(): void
    {
        $this->expectException(OrderDataException::class);
        InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext(null),
            quote: ShippingQuote::countryRequired(),
        );
    }

    public function testRejectsDigitalContextWithAvailableQuote(): void
    {
        $this->expectException(OrderDataException::class);
        InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(requiresShipping: false),
            shipping: new ShippingContext(null),
            quote: $this->quote(),
        );
    }

    public function testRejectsQuoteCurrencyThatDiffersFromCheckout(): void
    {
        $this->expectException(OrderDataException::class);
        InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: ShippingQuote::available([new ShippingOption(
                key: 'standard',
                label: 'Standard',
                amount: Money::of('5', 'USD'),
            )]),
        );
    }

    public function testMatchesOnlyOrderBuiltFromQuotedCheckoutFacts(): void
    {
        $shippingSnapshot = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );
        $physical = $this->orderLine(requiresShipping: true);
        $differentQuantity = $this->orderLine(
            requiresShipping: true,
            quantity: 2,
        );

        $this->assertTrue($shippingSnapshot->matches($this->order([$physical])));
        $this->assertFalse($shippingSnapshot->matches($this->order([$differentQuantity])));
    }

    /** @param array<string, mixed> $changes */
    #[DataProvider('changedInitiatingFacts')]
    public function testQuoteBindingIncludesTheCompleteResolvedLine(array $changes): void
    {
        $shippingSnapshot = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );
        $data = array_replace($this->orderLine(requiresShipping: true)->toArray(), $changes);

        $this->assertFalse($shippingSnapshot->matches($this->order([
            OrderLineItemSnapshot::fromArray($data),
        ])));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function changedInitiatingFacts(): iterable
    {
        yield 'name' => [['name' => 'Different product name']];
        yield 'description' => [['description' => 'Different description']];
        yield 'images' => [['images' => ['https://example.test/different.jpg']]];
        yield 'classification' => [['taxCode' => 'txcd_99999999']];
        yield 'price source and provider references' => [[
            'priceSource' => 'stripe',
            'stripePriceId' => 'price_other',
            'stripeProductId' => 'prod_other',
        ]];
    }

    private function checkout(
        bool $requiresShipping = true,
        string $locale = 'pt_PT',
    ): CheckoutContext {
        $price = Money::of('16', 'EUR');

        return new CheckoutContext(
            items: [new CheckoutLineItem(new Product(
                request: new ProductRequest('product', 1, []),
                name: 'Product',
                requiresShipping: $requiresShipping,
                price: new Price($price),
            ))],
            languageCode: 'pt',
            locale: $locale,
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
        );
    }

    private function quote(): ShippingQuote
    {
        return ShippingQuote::available([new ShippingOption(
            key: 'express',
            label: 'Expresso',
            amount: Money::of('5', 'EUR'),
            deliveryEstimate: new DeliveryEstimate(
                minimum: 1,
                maximum: 2,
                unit: DeliveryEstimateUnit::BusinessDay,
            ),
            taxBehavior: TaxBehavior::Inclusive,
            taxCode: 'txcd_92010001',
        )]);
    }

    private function orderLine(
        bool $requiresShipping,
        int $quantity = 1,
    ): OrderLineItemSnapshot {
        $price = Money::of('16', 'EUR');
        $product = new Product(
            request: new ProductRequest('product', $quantity),
            name: 'Product',
            requiresShipping: $requiresShipping,
            price: new Price($price),
        );

        return OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product));
    }

    /**
     * @param list<OrderLineItemSnapshot> $lineItems
     */
    private function order(
        array $lineItems,
    ): OrderCreationContext {
        return new OrderCreationContext(
            uuid: 'Abc123def456GHI7',
            orderNumber: 'ORD-ABC123DEF456GHI7',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: 'pt',
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: $lineItems,
        );
    }
}
