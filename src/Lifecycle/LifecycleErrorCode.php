<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Lifecycle;

/** Stable lifecycle error codes for exceptions and boundary mappings. */
final class LifecycleErrorCode
{
    public const CREATION_HOOK_FAILED = 'lifecycle.creation_hook_failed';

    public const DELIVERY_RECORD_FAILED = 'lifecycle.delivery_record_failed';

    public const LISTENER_FAILED = 'lifecycle.listener_failed';
}
