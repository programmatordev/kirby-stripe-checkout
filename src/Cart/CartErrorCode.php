<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

/** Stable cart error codes for exceptions and boundary mappings. */
final class CartErrorCode
{
    public const AMOUNT_INVALID = 'cart.amount_invalid';

    public const CONFIGURATION_INVALID = 'cart.configuration_invalid';

    public const ITEM_NOT_FOUND = 'cart.item_not_found';

    public const LINE_LIMIT_EXCEEDED = 'cart.line_limit_exceeded';

    public const PRODUCT_UNAVAILABLE = 'cart.product_unavailable';

    public const PROVIDER_UNAVAILABLE = 'cart.provider_unavailable';

    public const QUANTITY_INVALID = 'cart.quantity_invalid';

    public const RENDERER_FAILED = 'cart.renderer_failed';

    public const REVISION_CONFLICT = 'cart.revision_conflict';

    public const SELECTION_INVALID = 'cart.selection_invalid';

    public const SESSION_RESET = 'cart.session_reset';

    public const UNAVAILABLE = 'cart.unavailable';
}
