<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionCanonicalizer;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\SelectionData;
use ProgrammatorDev\StripeCheckout\Product\Support\ProductData;

/**
 * Immutable selection state; presentation and session serialization are separate concerns.
 *
 * @internal
 */
final readonly class CartSnapshot
{
    /** @var list<CartEntry> */
    private array $entries;

    /** @param array<array-key, CartEntry> $entries */
    public function __construct(
        private string $id,
        private string $revision,
        array $entries,
        private int $createdAt,
        private int $updatedAt,
        private ?string $destinationCountry = null,
    ) {
        ProductData::identifier($id);
        ProductData::identifier($revision);

        if (
            array_is_list($entries) === false
            || count($entries) > SelectionCanonicalizer::MAX_ENTRIES
            || $createdAt < 0
            || $updatedAt < $createdAt
            || ($destinationCountry !== null && preg_match('/\A[A-Z]{2}\z/', $destinationCountry) !== 1)
        ) {
            throw new InvalidArgumentException('Invalid cart snapshot.');
        }

        $seen = [];
        $quantity = 0;

        foreach ($entries as $entry) {
            foreach ($seen as $previous) {
                if ($entry->id() === $previous->id() || SelectionData::equivalent($entry->request(), $previous->request())) {
                    throw new InvalidArgumentException('Duplicate cart entry.');
                }
            }

            // Keep totalQuantity() representable even when no prices can resolve.
            $quantity = $quantity === 0
                ? $entry->request()->quantity()
                : SelectionData::addQuantities($quantity, $entry->request()->quantity());
            $seen[] = $entry;
        }

        $this->entries = $entries;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function revision(): string
    {
        return $this->revision;
    }

    /** @return list<CartEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function destinationCountry(): ?string
    {
        return $this->destinationCountry;
    }
}
