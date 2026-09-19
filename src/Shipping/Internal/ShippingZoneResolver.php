<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingResolverInterface;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZone;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingZoneScope;

/** Resolves the first exact country zone, then the optional fallback zone. */
final readonly class ShippingZoneResolver implements ShippingResolverInterface
{
    /** @var list<ShippingZone> */
    private array $zones;

    /** @param array<mixed> $zones */
    public function __construct(array $zones)
    {
        if (array_is_list($zones) === false) {
            throw new InvalidArgumentException('Shipping zones must be a list.');
        }

        foreach ($zones as $zone) {
            if ($zone instanceof ShippingZone === false) {
                throw new InvalidArgumentException('Shipping zones must contain ShippingZone values.');
            }
        }

        /** @var list<ShippingZone> $zones */
        $this->zones = $zones;
    }

    public function resolve(
        CheckoutContext $checkout,
        ShippingContext $shipping,
    ): ShippingQuote {
        $shippingCountry = $shipping->shippingCountry();

        if ($shippingCountry === null) {
            if ($this->zones === []) {
                return ShippingQuote::unavailable();
            }

            if (
                count($this->zones) === 1
                && $this->zones[0]->scope() === ShippingZoneScope::Fallback
            ) {
                return ShippingQuote::available($this->zones[0]->options());
            }

            return ShippingQuote::countryRequired();
        }

        $fallback = null;

        foreach ($this->zones as $zone) {
            if ($zone->scope() === ShippingZoneScope::Fallback) {
                $fallback = $zone;

                continue;
            }

            if (in_array($shippingCountry, $zone->countries(), true)) {
                return ShippingQuote::available($zone->options());
            }
        }

        return $fallback === null
            ? ShippingQuote::unavailable()
            : ShippingQuote::available($fallback->options());
    }
}
