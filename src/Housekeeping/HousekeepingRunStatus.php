<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Housekeeping;

enum HousekeepingRunStatus: string
{
    case Failed = 'failed';
    case PartialFailure = 'partial_failure';
    case Success = 'success';
}
