<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Integration;

use Brick\Money\Money;
use Kirby\Uuid\Uuid;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Kirby\OrderCreationContextFactory;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Test\Support\InitiatingShippingSnapshotFactory;
use ProgrammatorDev\StripeCheckout\Test\Support\KirbyTestCase;

final class OrderCreationContextFactoryTest extends KirbyTestCase
{
    public function testNewShippableAttemptRequiresAcceptedShippingQuote(): void
    {
        $this->expectException(OrderDataException::class);
        $this->create(requiresShipping: true);
    }

    public function testAcceptedShippingQuoteIsOnlyRequiredForShippableAttempt(): void
    {
        $physical = $this->create(
            requiresShipping: true,
            withShipping: true,
        );
        $digital = $this->create(requiresShipping: false);

        $this->assertTrue($physical->requiresShipping());
        $this->assertFalse($digital->requiresShipping());
    }

    public function testRejectsQuoteCalculatedForDifferentPurchaseFacts(): void
    {
        $this->expectException(OrderDataException::class);
        $this->create(
            requiresShipping: true,
            withShipping: true,
            quantity: 2,
        );
    }

    private function create(
        bool $requiresShipping,
        bool $withShipping = false,
        int $quantity = 1,
    ): OrderCreationContext {
        $price = Money::of('16', 'EUR');
        $product = new Product(
            request: new ProductRequest('product', $quantity),
            name: 'Product',
            requiresShipping: $requiresShipping,
            price: new Price($price),
        );

        return (new OrderCreationContextFactory($this->kirby))->create(
            uuid: Uuid::generate(),
            lineItems: [OrderLineItemSnapshot::fromProduct($product, $price)],
            currency: 'EUR',
            checkoutSource: CheckoutSource::Direct,
            cartRevision: null,
            userUuid: null,
            languageCode: null,
            uiMode: UiMode::Hosted,
            initiatingShipping: $withShipping
                ? InitiatingShippingSnapshotFactory::create(languageCode: null)
                : null,
        );
    }
}
