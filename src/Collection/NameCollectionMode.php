<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection;

/** Controls whether Checkout omits, optionally collects or requires a customer name. */
enum NameCollectionMode: string
{
    case Off = 'off';
    case Optional = 'optional';
    case Required = 'required';
}
