<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

/** Stable persistence error codes for exceptions and boundary mappings. */
final class PersistenceErrorCode
{
    public const BUSY = 'persistence.busy';

    public const CONTENT_INVALID = 'persistence.content_invalid';

    public const MODEL_MISMATCH = 'persistence.model_mismatch';

    public const ORDER_INVALID = 'persistence.order_invalid';

    public const ORDER_UNAVAILABLE = 'persistence.order_unavailable';

    public const OWNER_MISMATCH = 'persistence.owner_mismatch';

    public const REENTRANT_WRITE = 'persistence.reentrant_write';

    public const SCHEMA_UNSUPPORTED = 'persistence.schema_unsupported';

    public const USER_UUID_UNAVAILABLE = 'persistence.user_uuid_unavailable';

    public const UUID_UNAVAILABLE = 'persistence.uuid_unavailable';

    public const VERIFY_FAILED = 'persistence.verify_failed';

    public const WRITE_FAILED = 'persistence.write_failed';
}
