<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Selects the Stripe-hosted or embedded Checkout presentation. */
enum UiMode: string
{
    case Hosted = 'hosted';
    case Embedded = 'embedded';
}
