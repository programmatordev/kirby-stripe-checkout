<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Tax;

/** Store-wide tax inclusion policy for Kirby inline prices. */
enum TaxBehavior: string
{
    // Delegation by omission: Stripe applies its account policy, including its
    // own currency-aware automatic behavior. Do not reproduce that logic here.
    // https://docs.stripe.com/tax/products-prices-tax-codes-tax-behavior#set-default-tax-behavior
    case StripeDefault = 'stripe_default';
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
}
