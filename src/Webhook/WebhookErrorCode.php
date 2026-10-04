<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Webhook;

/** Stable codes for signed delivery verification and HTTP processing failures. */
final class WebhookErrorCode
{
    public const PAYLOAD_INVALID = 'webhook.payload_invalid';

    public const SIGNATURE_INVALID = 'webhook.signature_invalid';

    public const CORRELATION_INVALID = 'webhook.correlation_invalid';

    public const PROCESSING_UNAVAILABLE = 'webhook.processing_unavailable';
}
