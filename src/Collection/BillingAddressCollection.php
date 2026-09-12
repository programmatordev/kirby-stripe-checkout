<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

/** Controls whether Stripe decides to collect or requires a billing address. */
enum BillingAddressCollection: string
{
    case Auto = 'auto';
    case Required = 'required';
}
