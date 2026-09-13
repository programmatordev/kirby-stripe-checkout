<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Stable selection error codes for exceptions and boundary mappings. */
final class SelectionErrorCode
{
    public const INVALID = 'selection.invalid';

    public const LINE_LIMIT_EXCEEDED = 'selection.line_limit_exceeded';

    public const QUANTITY_INVALID = 'selection.quantity_invalid';
}
