<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

/** @internal Stable failure categories independent from stripe-php exception classes. */
enum CheckoutSessionFailureType: string
{
    case Rejected = 'provider_rejected';
    case Unavailable = 'provider_unavailable';
    case Uncertain = 'provider_uncertain';
    case Incompatible = 'provider_incompatible';
}
