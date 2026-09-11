<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout\Exception;

use RuntimeException;
use Throwable;

/**
 * Contains an SDK failure until orchestration classifies it safely.
 *
 * @internal
 */
final class CheckoutSessionGatewayException extends RuntimeException
{
    public function __construct(Throwable $error)
    {
        parent::__construct('The Stripe Checkout Session request failed.', previous: $error);
    }
}
