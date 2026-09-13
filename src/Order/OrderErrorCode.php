<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

/** Stable order error codes for exceptions and boundary mappings. */
final class OrderErrorCode
{
    public const CUSTOM_FIELDS_INVALID = 'order.custom_fields_invalid';

    public const DATA_INVALID = 'order.data_invalid';

    public const NUMBER_INVALID = 'order.number_invalid';

    public const QUERY_UNAVAILABLE = 'order.query_unavailable';

    public const UUID_SLUG_INCOMPATIBLE = 'order.uuid_slug_incompatible';

    public const UUID_UNAVAILABLE = 'order.uuid_unavailable';
}
