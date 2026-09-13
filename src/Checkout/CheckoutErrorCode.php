<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailureType;

/** Stable checkout error codes for exceptions and boundary mappings. */
final class CheckoutErrorCode
{
    public const ATTEMPT_CLOSED = 'checkout.attempt_closed';

    public const ATTEMPT_CONFLICT = 'checkout.attempt_conflict';

    public const ATTEMPT_RETRY_EXPIRED = 'checkout.attempt_retry_expired';

    public const ATTEMPT_TOKEN_INVALID = 'checkout.attempt_token_invalid';

    public const SESSION_ATTACHMENT_FAILED = 'checkout.session_attachment_failed';

    public const SESSION_INCOMPATIBLE = 'checkout.session_incompatible';

    public const SESSION_REJECTED = 'checkout.session_rejected';

    public const SESSION_UNAVAILABLE = 'checkout.session_unavailable';

    public const SESSION_UNCERTAIN = 'checkout.session_uncertain';

    /** Map provider failure categories to the plugin's public Session errors. */
    public static function forSessionFailure(CheckoutSessionFailureType $failureType): string
    {
        return match ($failureType) {
            CheckoutSessionFailureType::Rejected => self::SESSION_REJECTED,
            CheckoutSessionFailureType::Unavailable => self::SESSION_UNAVAILABLE,
            CheckoutSessionFailureType::Uncertain => self::SESSION_UNCERTAIN,
            CheckoutSessionFailureType::Incompatible => self::SESSION_INCOMPATIBLE,
        };
    }
}
