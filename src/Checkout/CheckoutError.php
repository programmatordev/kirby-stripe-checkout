<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use JsonSerializable;

/** A safe, translated Checkout failure without provider or persisted Order details. */
final readonly class CheckoutError implements JsonSerializable
{
    /** @internal Created at the Checkout HTTP boundary. */
    public function __construct(
        private string $code,
        private string $message,
        private bool $retryable,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    /** @return array{code: string, message: string, retryable: bool} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'retryable' => $this->retryable,
        ];
    }

    /** @return array{code: string, message: string, retryable: bool} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
