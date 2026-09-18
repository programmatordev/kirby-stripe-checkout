<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

/** Defines whether a zone owns explicit countries or the unmatched fallback. */
enum ShippingZoneScope: string
{
    case SelectedCountries = 'selected_countries';
    case Fallback = 'fallback';
}
