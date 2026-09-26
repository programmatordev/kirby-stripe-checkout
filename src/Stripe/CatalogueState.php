<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe;

/**
 * One last-good catalogue snapshot and its latest refresh outcome.
 *
 * @template T
 * @internal
 */
final readonly class CatalogueState
{
    /** @param list<T> $items */
    public function __construct(
        private array $items,
        private ?int $refreshedAt,
        private ?int $failedAt,
        private ?string $error,
    ) {}

    /** @return list<T> */
    public function items(): array
    {
        return $this->items;
    }

    public function refreshedAt(): ?int
    {
        return $this->refreshedAt;
    }

    public function failedAt(): ?int
    {
        return $this->failedAt;
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function status(): string
    {
        return match (true) {
            $this->error !== null && $this->items !== [] => 'stale',
            $this->error !== null => 'error',
            $this->refreshedAt !== null => 'ready',
            default => 'empty',
        };
    }

    /** @return self<T> */
    public function withFailure(int $failedAt, string $error): self
    {
        // A failed refresh must not discard the last usable provider snapshot.
        return new self(
            items: $this->items,
            refreshedAt: $this->refreshedAt,
            failedAt: $failedAt,
            error: $error,
        );
    }
}
