<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\InitiatingShippingSnapshot;
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

        $this->assertSame('PT', $snapshot->shippingCountry());
        $this->assertSame(['PT'], $snapshot->allowedCountries());
        $this->assertSame('pt', $snapshot->languageCode());
        $this->assertSame('pt_PT', $snapshot->locale());
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

        $this->assertNull($snapshot->shippingCountry());
        $this->assertSame(
            (new StripeShippingCountryRegistry())->codes(),
            $snapshot->allowedCountries(),
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

    public function testOrderContextKeepsShippingEvidenceTransient(): void
    {
        $shippingSnapshot = InitiatingShippingSnapshot::fromQuote(
            checkout: $this->checkout(),
            shipping: new ShippingContext('PT'),
            quote: $this->quote(),
        );
        $physical = $this->orderLine(requiresShipping: true);
        $digital = $this->orderLine(requiresShipping: false);
        $physicalOrder = $this->order([$physical], $shippingSnapshot);
        $digitalOrder = $this->order([$digital], null);

        $this->assertSame($shippingSnapshot, $physicalOrder->initiatingShipping());
        $this->assertNull($digitalOrder->initiatingShipping());
        $this->assertNull($this->order([$physical], null)->initiatingShipping());

        $this->expectException(OrderDataException::class);
        $this->order([$digital], $shippingSnapshot);
    }

    private function checkout(bool $requiresShipping = true): CheckoutContext
    {
        $price = Money::of('16', 'EUR');

        return new CheckoutContext(
            items: [new CheckoutLineItem(
                productReference: 'product',
                variantId: null,
                sku: null,
                quantity: 1,
                price: $price,
                subtotal: $price,
                requiresShipping: $requiresShipping,
                options: [],
                metadata: [],
            )],
            languageCode: 'pt',
            locale: 'pt_PT',
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

    private function orderLine(bool $requiresShipping): OrderLineItemSnapshot
    {
        $price = Money::of('16', 'EUR');
        $product = new Product(
            request: new ProductRequest('product'),
            name: 'Product',
            requiresShipping: $requiresShipping,
            price: new Price($price),
        );

        return OrderLineItemSnapshot::fromProduct($product, $price);
    }

    /**
     * @param list<OrderLineItemSnapshot> $lineItems
     */
    private function order(
        array $lineItems,
        ?InitiatingShippingSnapshot $shipping,
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
            initiatingShipping: $shipping,
        );
    }
}
