<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping;

/** Stable housekeeping error codes for operator outcomes and exceptions. */
final class HousekeepingErrorCode
{
    public const LOCKED = 'housekeeping.locked';

    public const PARTIAL_FAILURE = 'housekeeping.partial_failure';

    public const RUN_FAILED = 'housekeeping.run_failed';

    public const STATE_INVALID = 'housekeeping.state_invalid';

    public const WRITE_FAILED = 'housekeeping.write_failed';
}
