<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Exception;

use InvalidArgumentException;

/** Identifies an invalid shipping-option attribute without exposing its value. */
final class InvalidShippingOptionException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $attribute,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function attribute(): string
    {
        return $this->attribute;
    }
}
