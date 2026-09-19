<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

/** Describes whether Checkout can offer shipping for the current context. */
enum ShippingQuoteStatus: string
{
    case Available = 'available';
    case CountryRequired = 'country_required';
    case Unavailable = 'unavailable';
}
