<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Exception;

use RuntimeException;
use Throwable;

/** Reports a safe, path-aware failure from Session request customization. */
final class InvalidSessionRequestException extends RuntimeException
{
    public function __construct(
        private readonly string $requestErrorCode,
        private readonly ?string $requestPath = null,
        ?Throwable $previous = null,
    ) {
        $message = 'Stripe Checkout Session request rejected (' . $requestErrorCode . ')';

        if ($requestPath !== null) {
            $message .= ' at "' . $requestPath . '"';
        }

        parent::__construct($message . '.', previous: $previous);
    }

    public function errorCode(): string
    {
        return $this->requestErrorCode;
    }

    public function path(): ?string
    {
        return $this->requestPath;
    }
}
