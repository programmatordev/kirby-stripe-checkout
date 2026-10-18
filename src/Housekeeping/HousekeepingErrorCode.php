<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping;

/** Stable housekeeping error codes for operator outcomes and exceptions. */
final class HousekeepingErrorCode
{
    public const LOCKED = 'housekeeping.locked';

    public const STATE_INVALID = 'housekeeping.state_invalid';

    public const WRITE_FAILED = 'housekeeping.write_failed';
}
