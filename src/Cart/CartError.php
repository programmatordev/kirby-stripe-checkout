<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart;

/** A safe, translated failure without provider details or stored session data. */
final readonly class CartError
{
    /**
     * @internal Created at the cart presentation boundary.
     * @param array<string, bool|int|string> $context
     */
    public function __construct(
        private string $code,
        private string $message,
        private ?string $itemId = null,
        private ?string $field = null,
        private array $context = [],
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function itemId(): ?string
    {
        return $this->itemId;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    /** @return array<string, bool|int|string> */
    public function context(): array
    {
        return $this->context;
    }
}
