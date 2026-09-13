<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Stable request error codes for exceptions and boundary mappings. */
final class RequestErrorCode
{
    public const CSRF_INVALID = 'request.csrf_invalid';

    public const INVALID_BODY = 'request.invalid_body';

    public const UNSUPPORTED_MEDIA_TYPE = 'request.unsupported_media_type';
}
