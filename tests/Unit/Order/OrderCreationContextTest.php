<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;

final class OrderCreationContextTest extends TestCase
{
    public function testCreationContextContainsOnlyFrozenInitiatingFacts(): void
    {
        $context = OrderFixture::context();
        $this->assertSame('Abc123def456GHI7', $context->uuid());
        $this->assertSame('page://Abc123def456GHI7', $context->pageUuid());
        $this->assertSame('ORD-ABC123DEF456GHI7', $context->orderNumber());
        $this->assertSame(CheckoutSource::Cart, $context->checkoutSource());
        $this->assertSame('revision', $context->cartRevision());
        $this->assertNull($context->userUuid());
        $this->assertSame('en', $context->languageCode());
        $this->assertSame(UiMode::Hosted, $context->uiMode());
        $this->assertSame('EUR', $context->currency());
        $this->assertSame('32.00', (string) $context->subtotal()->getAmount());
        $this->assertTrue($context->requiresShipping());
        $lineItems = $context->lineItems();
        $this->assertIsArray($lineItems[0]['options']);
        $this->assertIsArray($lineItems[0]['options'][0]);
        $this->assertSame('Large', $lineItems[0]['options'][0]['valueName']);
        $this->assertSame('SHIRT-L', $lineItems[0]['sku']);
        $lineItems[0]['name'] = 'Changed';
        $this->assertSame('T-shirt', $context->lineItems()[0]['name']);
    }

    public function testContextRejectsMixedSources(): void
    {
        $product = new Product(
            request: new ProductRequest('other'),
            name: 'Other',
            requiresShipping: false,
            price: new StripePriceReference('price_other'),
        );
        $stripePrice = new StripePrice(
            priceId: 'price_other',
            productId: 'prod_other',
            name: 'Provider product',
            unitPrice: (new StripeCurrencyRegistry())->fromMoney(Money::of('16', 'EUR')),
            taxBehavior: \Stripe\Price::TAX_BEHAVIOR_UNSPECIFIED,
        );
        $stripeLineItem = OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product, $stripePrice));

        $this->expectException(OrderDataException::class);
        OrderFixture::context(lineItems: [OrderFixture::lineItem(), $stripeLineItem]);
    }

    public function testContextRejectsMixedCurrencies(): void
    {
        $product = new Product(
            request: new ProductRequest('other'),
            name: 'Other',
            requiresShipping: false,
            price: new Price(Money::of('16', 'USD')),
        );
        $lineItem = OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product));
        $this->expectException(OrderDataException::class);
        OrderFixture::context(lineItems: [OrderFixture::lineItem(), $lineItem]);
    }

    /** @param array{lineItems?: array<array-key, OrderLineItemSnapshot>, checkoutSource?: CheckoutSource, revision?: string|null, language?: string, user?: string} $arguments */
    #[DataProvider('invalidContexts')]
    public function testContextRejectsBrokenIdentityOrSourceFacts(array $arguments): void
    {
        $this->expectException(OrderDataException::class);
        OrderFixture::context(...$arguments);
    }

    /** @return iterable<string, array{array{lineItems?: array<array-key, OrderLineItemSnapshot>, checkoutSource?: CheckoutSource, revision?: string|null, language?: string, user?: string}}> */
    public static function invalidContexts(): iterable
    {
        yield 'cart without revision' => [['revision' => null]];
        yield 'direct with cart revision' => [['checkoutSource' => CheckoutSource::Direct]];
        yield 'email instead of user UUID' => [['user' => 'customer@example.com']];
        yield 'user UUID with path' => [['user' => 'user://customer/path']];
        yield 'empty language' => [['language' => '']];
        yield 'empty line items' => [['lineItems' => []]];
        yield 'too many line items' => [['lineItems' => array_fill(0, 101, OrderFixture::lineItem())]];
        yield 'non-list line items' => [['lineItems' => ['item' => OrderFixture::lineItem()]]];
    }
}
