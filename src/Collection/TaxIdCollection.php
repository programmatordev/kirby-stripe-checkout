<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

/** Controls Stripe Checkout tax-ID collection independently from Automatic Tax. */
enum TaxIdCollection: string
{
    case Off = 'off';
    case Optional = 'optional';
    case RequiredIfSupported = 'required_if_supported';
}
