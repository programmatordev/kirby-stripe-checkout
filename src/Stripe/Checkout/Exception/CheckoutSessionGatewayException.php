<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception;

use ProgrammatorDev\StripeCheckout\Stripe\Checkout\CheckoutSessionFailure;
use RuntimeException;
use Throwable;

/**
 * Keeps an SDK failure behind its sanitized classification at the gateway edge.
 *
 * @internal
 */
final class CheckoutSessionGatewayException extends RuntimeException
{
    public function __construct(
        private readonly CheckoutSessionFailure $failure,
        Throwable $error,
    ) {
        parent::__construct('The Stripe Checkout Session request failed.', previous: $error);
    }

    public function failure(): CheckoutSessionFailure
    {
        return $this->failure;
    }
}
