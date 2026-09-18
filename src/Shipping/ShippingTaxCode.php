<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

/** Store-wide classification choices for built-in shipping options. */
enum ShippingTaxCode: string
{
    case StripeDefault = 'stripe_default';
    case Shipping = 'shipping';
    case Nontaxable = 'nontaxable';

    public function taxCode(): ?string
    {
        return match ($this) {
            self::StripeDefault => null,
            self::Shipping => 'txcd_92010001',
            self::Nontaxable => 'txcd_00000000',
        };
    }
}
