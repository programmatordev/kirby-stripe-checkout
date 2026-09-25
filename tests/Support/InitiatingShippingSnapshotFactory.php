<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\InitiatingShippingSnapshot;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;

/** Provides a standard delivery quote for tests that do not exercise rate selection. */
final class InitiatingShippingSnapshotFactory
{
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
}
