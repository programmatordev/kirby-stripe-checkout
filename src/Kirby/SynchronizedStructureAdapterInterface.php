<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Kirby;

/**
 * Defines one schema that can be stored canonically and translated by overlay.
 *
 * @internal
 */
interface SynchronizedStructureAdapterInterface
{
    /** @return list<array<string, mixed>> */
    public function canonical(mixed $value): array;

    /**
     * @param list<array<string, mixed>> $canonical
     * @return list<array<string, mixed>>
     */
    public function localized(array $canonical, mixed $overlay): array;

    /**
     * @param list<array<string, mixed>> $canonical
     * @return list<array<string, mixed>>
     */
    public function overlay(array $canonical, mixed $value): array;
}
