<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping;

/** Stable shipping error and quote reason codes for boundary mappings. */
final class ShippingErrorCode
{
    public const CURRENCY_MISMATCH = 'shipping.currency_mismatch';

    public const COUNTRY_INVALID = 'shipping.country_invalid';

    public const COUNTRY_REQUIRED = 'shipping.country_required';

    public const FILTER_FAILED = 'shipping.filter_failed';

    public const FILTER_INVALID = 'shipping.filter_invalid';

    public const INVALID = 'shipping.invalid';

    public const RESOLVER_FAILED = 'shipping.resolver_failed';

    public const UNAVAILABLE = 'shipping.unavailable';
}
