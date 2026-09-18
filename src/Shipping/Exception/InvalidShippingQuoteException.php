<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Shipping\Exception;

use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use Throwable;

final class InvalidShippingQuoteException extends ShippingException
{
    public function __construct(
        string $errorCode = ShippingErrorCode::INVALID,
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $previous);
    }
}
