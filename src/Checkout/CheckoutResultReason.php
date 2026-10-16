<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Describes how the browser reached its Checkout destination, independently of payment state. */
enum CheckoutResultReason: string
{
    case HostedSuccess = 'hosted_success';
    case HostedCancel = 'hosted_cancel';
    case EmbeddedReturn = 'embedded_return';
}
