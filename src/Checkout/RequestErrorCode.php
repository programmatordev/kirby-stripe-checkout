<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/** Stable request error codes for exceptions and boundary mappings. */
final class RequestErrorCode
{
    public const CSRF_INVALID = 'request.csrf_invalid';

    public const INVALID_BODY = 'request.invalid_body';

    public const ORIGIN_INVALID = 'request.origin_invalid';

    public const UNSUPPORTED_REPRESENTATION = 'request.unsupported_representation';

    public const UNSUPPORTED_MEDIA_TYPE = 'request.unsupported_media_type';
}
