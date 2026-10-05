<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

/** @internal Outcome of one manual reconciliation, independent of optional hook delivery success. */
enum ReconciliationOutcome: string
{
    case Updated = 'updated';
    case NoChange = 'no_change';
    case Failed = 'failed';
}
