<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Stable session request error codes for exceptions and boundary mappings. */
final class SessionRequestErrorCode
{
    public const FILTER_FAILED = 'session_request.filter_failed';

    public const FILTER_INVALID = 'session_request.filter_invalid';

    public const INVARIANT_VIOLATION = 'session_request.invariant_violation';

    public const PARAMETER_INVALID = 'session_request.parameter_invalid';

    public const PARAMETER_PROTECTED = 'session_request.parameter_protected';
}
