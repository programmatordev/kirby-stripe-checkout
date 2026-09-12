<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Collection\Exception;

use InvalidArgumentException;

/** Identifies the invalid attribute without exposing its configured value. */
final class InvalidCustomFieldException extends InvalidArgumentException
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
