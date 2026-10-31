<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

/** @internal Fixed operational attention filters for the Orders index. */
enum OrderAttentionFilter: string
{
    case FailedEvents = 'failed_events';
    case FailedHooks = 'failed_hooks';
}
