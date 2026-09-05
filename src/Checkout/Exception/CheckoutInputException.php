<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Exception;

use RuntimeException;
use Throwable;

/** A stable input failure; translation and HTTP status belong to the request edge. */
final class CheckoutInputException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Stripe Checkout input rejected (' . $errorCode . ').', previous: $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
