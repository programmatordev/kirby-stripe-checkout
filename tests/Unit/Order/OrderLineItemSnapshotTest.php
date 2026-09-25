<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Order;

use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;
use ProgrammatorDev\StripeCheckout\Test\Support\OrderFixture;
use stdClass;

final class OrderLineItemSnapshotTest extends TestCase
{
    #[DataProvider('currencies')]
    public function testExactPriceAndProviderUnits(string $currency, string $amount, int $providerPrice): void
    {
        $price = Money::of($amount, $currency);
        $product = new Product(
            request: new ProductRequest('product', 2),
            name: 'Product',
            requiresShipping: false,
            price: new Price($price),
        );
        $lineItem = OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product));
        $data = $lineItem->toArray();
        $this->assertSame([
            'price' => $providerPrice,
            'subtotal' => $providerPrice * 2,
        ], $data['providerAmounts']);
        $this->assertSame((string) $price->getAmount(), $data['price']);
        $this->assertSame($data, OrderLineItemSnapshot::fromArray($data)->toArray());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function currencies(): iterable
    {
        yield 'zero price' => ['EUR', '0', 0];
        yield 'two decimals' => ['EUR', '16.00', 1600];
        yield 'zero decimals' => ['JPY', '16', 16];
        yield 'three ISO decimals' => ['BHD', '16.12', 1612];
        yield 'ISK whole two decimals' => ['ISK', '16', 1600];
        yield 'UGX whole two decimals' => ['UGX', '16', 1600];
        yield 'MGA zero provider decimals' => ['MGA', '16', 16];
    }

    public function testStripeLineItemsRetainResolvedMoneyAndPriceProductReferences(): void
    {
        $product = new Product(
            request: new ProductRequest('product'),
            name: 'Stripe product',
            requiresShipping: false,
            price: new StripePriceReference('price_test'),
        );
        $stripePrice = new StripePrice(
            priceId: 'price_test',
            productId: 'prod_test',
            name: 'Provider product',
            unitPrice: (new StripeCurrencyRegistry())->fromMoney(Money::of('25', 'EUR')),
            taxBehavior: \Stripe\Price::TAX_BEHAVIOR_UNSPECIFIED,
        );
        $lineItem = OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product, $stripePrice));
        $data = $lineItem->toArray();
        $this->assertSame('stripe', $data['priceSource']);
        $this->assertSame('price_test', $data['stripePriceId']);
        $this->assertSame('prod_test', $data['stripeProductId']);
        $this->assertSame('25.00', $data['price']);
    }

    #[DataProvider('invalidLineItems')]
    public function testRejectsCorruptOrUnknownInitiatingLineItemFacts(string $field, mixed $value): void
    {
        $data = OrderFixture::lineItemData();
        $data[$field] = $value;
        $this->expectException(OrderDataException::class);
        OrderLineItemSnapshot::fromArray($data);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidLineItems(): iterable
    {
        yield 'float' => ['price', 16.0];
        yield 'negative' => ['price', '-16'];
        yield 'inexact' => ['price', '16.001'];
        yield 'overflow' => ['price', '99999999999999999999999999999'];
        yield 'wrong total' => ['subtotal', '33.00'];
        yield 'provider units' => ['providerAmounts', [
            'price' => 16,
            'subtotal' => 32,
        ]];
        yield 'string provider units' => ['providerAmounts', [
            'price' => '1600',
            'subtotal' => 3200,
        ]];
        yield 'quantity' => ['quantity', 0];
        yield 'fraction quantity' => ['quantity', 1.5];
        yield 'source mix' => ['stripePriceId', 'price_test'];
        yield 'missing options' => ['options', []];
        yield 'unknown fact' => ['cardNumber', 'private'];
        yield 'unknown option' => ['options', [[
            'optionId' => 'size',
            'optionName' => 'Size',
            'valueId' => 'large',
            'valueName' => 'Large',
            'extra' => true,
        ]]];
        yield 'SDK object' => ['metadata', new stdClass()];
    }

    public function testFreezesProductDetailsAndSelectedOptions(): void
    {
        $product = new Product(
            request: new ProductRequest('page://shirt', 2, ['size' => 'large']),
            name: 'T-shirt',
            requiresShipping: true,
            price: new Price(Money::of('16', 'EUR')),
            selectedOptions: [new SelectedOption('size', 'Size', 'large', 'Large')],
            imageUrls: ['https://example.com/shirt.jpg'],
            sku: 'SHIRT-L',
            variantId: 'large-variant',
            taxCode: new TaxCode('txcd_33020002'),
        );

        $snapshot = OrderLineItemSnapshot::fromCheckoutLineItem(new CheckoutLineItem($product));
        $expected = OrderFixture::lineItemData();
        $expected['taxCode'] = 'txcd_33020002';
        ksort($expected);

        $this->assertSame($expected, $snapshot->toArray());
    }

    #[DataProvider('requiredSnapshotKeys')]
    public function testSnapshotKeysCannotBeOmittedOrReplaced(string $scope, string $key, bool $replace): void
    {
        $lineItem = OrderFixture::lineItemData();
        $option = OrderData::map(OrderData::list($lineItem['options'])[0]);
        $snapshot = $scope === 'option' ? $option : $lineItem;
        unset($snapshot[$key]);

        if ($replace) {
            $snapshot['unexpected'] = null;
        }

        if ($scope === 'option') {
            $lineItem['options'] = [$snapshot];
        } else {
            $lineItem = $snapshot;
        }

        $this->expectException(OrderDataException::class);
        OrderLineItemSnapshot::fromArray($lineItem);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function requiredSnapshotKeys(): iterable
    {
        $fields = [
            'line' => ['description', 'sku', 'variantId', 'stripePriceId', 'stripeProductId'],
            'option' => ['optionId', 'optionName', 'valueId', 'valueName'],
        ];

        foreach ($fields as $scope => $keys) {
            foreach ($keys as $key) {
                yield $scope . '.' . $key . ' missing' => [$scope, $key, false];
            }

            yield $scope . ' unknown replacement' => [$scope, $keys[0], true];
        }
    }
}
