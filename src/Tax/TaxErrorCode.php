<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Tax;

/** Stable tax error codes for exceptions and boundary mappings. */
final class TaxErrorCode
{
    public const CATALOGUE_UNAVAILABLE = 'tax.catalogue_unavailable';

    public const CODE_INVALID = 'tax.code_invalid';
}
