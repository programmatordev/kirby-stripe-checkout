<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Checkout;

use Brick\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;

final class CheckoutContextTest extends TestCase
{
    public function testDerivesTotalsCurrencyAndShippableItems(): void
    {
        $digital = self::lineItem(
            reference: 'page://digital',
            amount: '10.00',
            quantity: 2,
            requiresShipping: false,
        );
        $physical = self::lineItem(
            reference: 'page://physical',
            amount: '15.00',
            quantity: 1,
            requiresShipping: true,
        );
        $context = self::context([$digital, $physical]);

        $this->assertSame([$digital, $physical], $context->items());
        $this->assertSame([$physical], $context->shippableItems());
        $this->assertSame('EUR', $context->currency()->getCurrencyCode());
        $this->assertSame('35.00', (string) $context->subtotal()->getAmount());
        $this->assertSame('pt', $context->languageCode());
        $this->assertSame('pt_PT', $context->locale());
        $this->assertSame('user://customer', $context->userUuid());
        $this->assertSame(CheckoutSource::Cart, $context->checkoutSource());
        $this->assertSame(UiMode::Hosted, $context->uiMode());
    }

    public function testLineItemExposesResolvedProductFacts(): void
    {
        $option = new SelectedOption('size', 'Size', 'medium', 'Medium');
        $lineItem = new CheckoutLineItem(new Product(
            request: new ProductRequest('page://shirt', 2, ['size' => 'medium']),
            name: 'Product',
            requiresShipping: true,
            price: new Price(Money::of('20.00', 'EUR')),
            selectedOptions: [$option],
            sku: 'SHIRT-M',
            metadata: ['shipping_class' => 'parcel'],
            variantId: 'variant-medium',
        ));

        $this->assertSame('page://shirt', $lineItem->productReference());
        $this->assertSame('variant-medium', $lineItem->variantId());
        $this->assertSame('SHIRT-M', $lineItem->sku());
        $this->assertSame(2, $lineItem->quantity());
        $this->assertSame('20.00', (string) $lineItem->price()->getAmount());
        $this->assertSame('40.00', (string) $lineItem->subtotal()->getAmount());
        $this->assertTrue($lineItem->requiresShipping());
        $this->assertSame([$option], $lineItem->options());
        $this->assertSame(['shipping_class' => 'parcel'], $lineItem->metadata());
    }

    public function testRejectsMixedCurrencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::context([
            self::lineItem(),
            self::lineItem(reference: 'page://second', currency: 'USD'),
        ]);
    }

    /** @param list<CheckoutLineItem> $items */
    private static function context(array $items): CheckoutContext
    {
        return new CheckoutContext(
            items: $items,
            languageCode: 'pt',
            locale: 'pt_PT',
            userUuid: 'user://customer',
            checkoutSource: CheckoutSource::Cart,
            uiMode: UiMode::Hosted,
        );
    }

    private static function lineItem(
        string $reference = 'page://product',
        string $amount = '10.00',
        string $currency = 'EUR',
        int $quantity = 1,
        bool $requiresShipping = true,
    ): CheckoutLineItem {
        return new CheckoutLineItem(new Product(
            request: new ProductRequest($reference, $quantity, []),
            name: 'Product',
            requiresShipping: $requiresShipping,
            price: new Price(Money::of($amount, $currency)),
        ));
    }
}
