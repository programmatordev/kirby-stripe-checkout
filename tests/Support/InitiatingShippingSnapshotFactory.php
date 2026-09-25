<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\Price;
use ProgrammatorDev\StripeCheckout\Product\Product;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;
use ProgrammatorDev\StripeCheckout\Product\StripePriceReference;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Stripe\Price\StripePrice;
use ProgrammatorDev\StripeCheckout\Tax\TaxCode;

/** Creates complete initiating shipping evidence for unrelated order tests. */
final class InitiatingShippingSnapshotFactory
{
    public static function create(
        string $currency = 'EUR',
        ?string $shippingCountry = 'PT',
        ?string $languageCode = 'en',
        string $locale = 'en_US',
    ): InitiatingShippingSnapshot {
        $price = Money::of('16', $currency);
        $checkout = new CheckoutContext(
            items: [new CheckoutLineItem(new Product(
                request: new ProductRequest('product', 1, []),
                name: 'Product',
                requiresShipping: true,
                price: new Price($price),
            ))],
            languageCode: $languageCode,
            locale: $locale,
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
        );

        return self::fromCheckout($checkout, $shippingCountry);
    }

    /** @param list<ShippingOption>|null $options */
    public static function fromCheckout(
        CheckoutContext $checkout,
        ?string $shippingCountry = 'PT',
        ?array $options = null,
    ): InitiatingShippingSnapshot {
        $shipping = new ShippingContext($shippingCountry);
        $quote = ShippingQuote::available($options ?? [new ShippingOption(
            key: 'standard',
            label: 'Standard delivery',
            amount: Money::of('5', $checkout->currency()),
            deliveryEstimate: new DeliveryEstimate(
                minimum: 2,
                maximum: 4,
                unit: DeliveryEstimateUnit::BusinessDay,
            ),
        )]);

        return InitiatingShippingSnapshot::fromQuote($checkout, $shipping, $quote);
    }

    /** @param list<ShippingOption>|null $options */
    public static function fromOrder(
        OrderCreationContext $order,
        ?string $shippingCountry = 'PT',
        string $locale = 'en_US',
        ?array $options = null,
    ): InitiatingShippingSnapshot {
        return self::fromCheckout(
            self::checkoutFromOrder($order, $locale),
            $shippingCountry,
            $options,
        );
    }

    public static function checkoutFromOrder(
        OrderCreationContext $order,
        string $locale = 'en_US',
    ): CheckoutContext {
        $items = array_map(
            static function (array $lineItem): CheckoutLineItem {
                $options = array_map(
                    static function (mixed $option): SelectedOption {
                        $option = OrderData::map($option);

                        return new SelectedOption(
                            optionId: OrderData::text($option['optionId'] ?? null),
                            optionName: OrderData::text($option['optionName'] ?? null),
                            valueId: OrderData::text($option['valueId'] ?? null),
                            valueName: OrderData::text($option['valueName'] ?? null),
                        );
                    },
                    OrderData::list($lineItem['options']),
                );

                $selection = [];

                foreach ($options as $option) {
                    $selection[$option->optionId()] = $option->valueId();
                }

                $price = Money::of(
                    OrderData::text($lineItem['price']),
                    OrderData::text($lineItem['currency']),
                );
                $stripePrice = $lineItem['priceSource'] === 'stripe'
                    ? new StripePrice(
                        priceId: OrderData::text($lineItem['stripePriceId']),
                        productId: OrderData::text($lineItem['stripeProductId']),
                        name: OrderData::text($lineItem['name']),
                        unitPrice: (new StripeCurrencyRegistry())->fromMoney($price),
                        taxBehavior: \Stripe\Price::TAX_BEHAVIOR_UNSPECIFIED,
                    )
                    : null;
                $product = new Product(
                    request: new ProductRequest(
                        reference: OrderData::text($lineItem['reference']),
                        quantity: OrderData::integer($lineItem['quantity']),
                        selectedOptions: $selection,
                    ),
                    name: OrderData::text($lineItem['name']),
                    requiresShipping: OrderData::boolean($lineItem['requiresShipping']),
                    price: $stripePrice === null ? new Price($price) : new StripePriceReference($stripePrice->priceId()),
                    selectedOptions: $options,
                    description: OrderData::nullableString($lineItem['description']),
                    imageUrls: OrderData::list($lineItem['images']),
                    sku: OrderData::nullableString($lineItem['sku']),
                    metadata: OrderData::map($lineItem['metadata']),
                    variantId: OrderData::nullableString($lineItem['variantId']),
                    taxCode: $lineItem['taxCode'] === null ? null : new TaxCode(OrderData::text($lineItem['taxCode'])),
                );

                return new CheckoutLineItem($product, $stripePrice);
            },
            $order->lineItems(),
        );

        return new CheckoutContext(
            items: $items,
            languageCode: $order->languageCode(),
            locale: $locale,
            userUuid: $order->userUuid(),
            checkoutSource: $order->checkoutSource(),
            uiMode: $order->uiMode(),
        );
    }
}
