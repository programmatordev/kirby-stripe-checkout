<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

/**
 * Keeps the stored and submitted forms of one setting comparison together.
 * Both forms are required when a localized submission may only change labels
 * while canonical technical values remain owned by the default language.
 *
 * @template T
 * @internal
 */
final readonly class SettingUpdate
{
    /**
     * @param T $storedValue
     * @param T $candidateValue
     */
    public function __construct(
        private mixed $storedValue,
        private mixed $candidateValue,
    ) {}

    /** @return T */
    public function storedValue(): mixed
    {
        return $this->storedValue;
    }

    /** @return T */
    public function candidateValue(): mixed
    {
        return $this->candidateValue;
    }
}
