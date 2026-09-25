<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use Brick\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Configuration\PriceSource;
use ProgrammatorDev\StripeCheckout\Money\MoneySnapshot;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;

final class CheckoutLineItemTest extends TestCase
{
    public function testInlineAmountsAndClassificationComeFromTheProduct(): void
    {
        $product = new Product(
            request: new ProductRequest('product', 3),
            name: 'Product',
            requiresShipping: true,
            price: new Price(Money::of('16', 'EUR')),
            taxCode: new TaxCode('txcd_99999999'),
        );
        $lineItem = new CheckoutLineItem($product);

        $this->assertSame('16.00', (string) $lineItem->price()->getAmount());
        $this->assertSame('48.00', (string) $lineItem->subtotal()->getAmount());
        $this->assertSame('EUR', $lineItem->subtotal()->getCurrency()->getCurrencyCode());
        $this->assertSame(PriceSource::Kirby, $lineItem->priceSource());
        $this->assertSame($product->taxCode(), $lineItem->taxCode());
        $this->assertNull($lineItem->stripePriceId());
        $this->assertNull($lineItem->stripeProductId());
    }

    #[DataProvider('inconsistentSources')]
    public function testRejectsIncompleteOrMixedPriceResolution(Price|StripePriceReference $price, ?StripePrice $stripePrice): void
    {
        $product = new Product(new ProductRequest('product'), 'Product', false, $price);
        $this->expectException(InvalidArgumentException::class);

        new CheckoutLineItem($product, $stripePrice);
    }

    /** @return iterable<string, array{Price|StripePriceReference, ?StripePrice}> */
    public static function inconsistentSources(): iterable
    {
        $stripePrice = new StripePrice(
            priceId: 'price_test',
            productId: 'prod_test',
            name: 'Provider product',
            unitPrice: new MoneySnapshot('EUR', 2500),
            taxBehavior: \Stripe\Price::TAX_BEHAVIOR_UNSPECIFIED,
        );

        yield 'unresolved Stripe price' => [new StripePriceReference('price_test'), null];
        yield 'different Stripe reference' => [new StripePriceReference('price_other'), $stripePrice];
        yield 'Stripe price for inline source' => [new Price(Money::of('16', 'EUR')), $stripePrice];
    }

    public function testStripeClassificationCannotBeReplacedByALocalTaxCode(): void
    {
        $product = new Product(
            request: new ProductRequest('product'),
            name: 'Local name',
            requiresShipping: false,
            price: new StripePriceReference('price_test'),
            taxCode: new TaxCode('txcd_99999999'),
        );
        $lineItem = new CheckoutLineItem($product, new StripePrice(
            priceId: 'price_test',
            productId: 'prod_test',
            name: 'Provider name',
            unitPrice: new MoneySnapshot('EUR', 2500),
            taxBehavior: \Stripe\Price::TAX_BEHAVIOR_EXCLUSIVE,
            taxCode: 'txcd_30011000',
        ));

        $this->assertSame(PriceSource::Stripe, $lineItem->priceSource());
        $this->assertSame('price_test', $lineItem->stripePriceId());
        $this->assertSame('prod_test', $lineItem->stripeProductId());
        $this->assertSame('Local name', $lineItem->name());
        $this->assertSame('25.00', (string) $lineItem->price()->getAmount());
        $this->assertNull($lineItem->taxCode());
    }
}
