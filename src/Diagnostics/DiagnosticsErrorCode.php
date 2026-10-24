<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

/** Stable failures for explicit remote diagnostics. */
final class DiagnosticsErrorCode
{
    public const AUTHENTICATION_FAILED = 'diagnostics.authentication_failed';

    public const PERMISSION_DENIED = 'diagnostics.permission_denied';

    public const REMOTE_INCOMPATIBLE = 'diagnostics.remote_incompatible';

    public const REMOTE_REJECTED = 'diagnostics.remote_rejected';

    public const REMOTE_UNAVAILABLE = 'diagnostics.remote_unavailable';
}
