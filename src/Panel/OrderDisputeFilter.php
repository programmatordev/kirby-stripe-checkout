<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

/** @internal Fixed dispute filters for the Orders index. */
enum OrderDisputeFilter: string
{
    case Any = 'any';
    case RequiresResponse = 'requires_response';
}
