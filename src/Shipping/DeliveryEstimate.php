<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingOptionException;

/** Exposes one validated Stripe-compatible delivery-estimate range. */
final readonly class DeliveryEstimate
{
    public function __construct(
        private ?int $minimum,
        private ?int $maximum,
        private DeliveryEstimateUnit $unit,
    ) {
        // Stripe permits either bound, but every supplied value must be positive.
        // https://docs.stripe.com/api/checkout/sessions/create#checkout_session_create-shipping_options-shipping_rate_data-delivery_estimate
        if ($minimum === null && $maximum === null) {
            throw new InvalidShippingOptionException(
                'deliveryEstimate',
                'A delivery estimate requires at least one bound.',
            );
        }

        if ($minimum !== null && $minimum < 1) {
            throw new InvalidShippingOptionException(
                'deliveryEstimate.minimum',
                'A delivery-estimate minimum must be positive.',
            );
        }

        if ($maximum !== null && $maximum < 1) {
            throw new InvalidShippingOptionException(
                'deliveryEstimate.maximum',
                'A delivery-estimate maximum must be positive.',
            );
        }

        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidShippingOptionException(
                'deliveryEstimate.maximum',
                'A delivery-estimate maximum cannot be below its minimum.',
            );
        }
    }

    public function minimum(): ?int
    {
        return $this->minimum;
    }

    public function maximum(): ?int
    {
        return $this->maximum;
    }

    public function unit(): DeliveryEstimateUnit
    {
        return $this->unit;
    }

    /** @return array{minimum: ?int, maximum: ?int, unit: string} */
    public function toArray(): array
    {
        return [
            'minimum' => $this->minimum,
            'maximum' => $this->maximum,
            'unit' => $this->unit->value,
        ];
    }
}
