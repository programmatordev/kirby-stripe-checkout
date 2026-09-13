<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

/** Stable prices error codes for exceptions and boundary mappings. */
final class PriceCatalogueErrorCode
{
    public const CONFIGURATION_INVALID = 'prices.configuration_invalid';

    public const REFRESH_FAILED = 'prices.refresh_failed';
}
