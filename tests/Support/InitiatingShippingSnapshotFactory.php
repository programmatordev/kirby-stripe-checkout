<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;

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
            items: [new CheckoutLineItem(
                productReference: 'product',
                variantId: null,
                sku: null,
                quantity: 1,
                price: $price,
                subtotal: $price,
                requiresShipping: true,
                options: [],
                metadata: [],
            )],
            languageCode: $languageCode,
            locale: $locale,
            userUuid: null,
            checkoutSource: CheckoutSource::Direct,
            uiMode: UiMode::Hosted,
        );

        return self::fromCheckout($checkout, $shippingCountry);
    }

    public static function fromCheckout(
        CheckoutContext $checkout,
        ?string $shippingCountry = 'PT',
    ): InitiatingShippingSnapshot {
        $shipping = new ShippingContext($shippingCountry);
        $quote = ShippingQuote::available([new ShippingOption(
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
}
