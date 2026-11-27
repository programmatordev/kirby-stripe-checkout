<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

use Kirby\Form\Field\StatsField;

/** Keeps Kirby's native stats data while selecting the order-status Panel field. */
final class OrderStatusField extends StatsField
{
    /** @return array<mixed> */
    public function props(): array
    {
        return [
            ...parent::props(),
            // FieldClass replaces a missing label with the humanized field name.
            'label' => false,
        ];
    }

    public function type(): string
    {
        return 'stripe-checkout-order-status';
    }
}
