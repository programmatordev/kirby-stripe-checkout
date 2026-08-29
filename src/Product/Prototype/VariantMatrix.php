<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Prototype;

use Closure;

/**
 * Reconciles generated product combinations without discarding merchant data.
 *
 * @internal The variant storage contract remains provisional until batch 6.2b2.
 */
final class VariantMatrix
{
    /** @var Closure(array<string, string>): string */
    private readonly Closure $idGenerator;

    /** @param (Closure(array<string, string>): string)|null $idGenerator */
    public function __construct(?Closure $idGenerator = null)
    {
        $this->idGenerator = $idGenerator ?? self::generatedId(...);
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $groups
     * @param list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}> $variants
     * @return list<array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>
     */
    public function reconcile(array $groups, array $variants): array
    {
        if ($groups === []) {
            return [];
        }

        $existing = [];

        foreach ($variants as $variant) {
            $existing[self::choiceKey($variant['choices'])] = $variant;
        }

        $reconciled = [];

        foreach ($this->combinations($groups) as $choices) {
            $key = self::choiceKey($choices);
            $variant = $existing[$key] ?? null;

            $reconciled[] = $variant ?? [
                'id' => ($this->idGenerator)($choices),
                'choices' => $choices,
                'enabled' => true,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ];
        }

        return $reconciled;
    }

    /** @param array<string, string> $choices */
    public static function choiceKey(array $choices): string
    {
        ksort($choices);

        return implode('|', array_map(
            static fn(string $groupId, string $valueId): string => $groupId . ':' . $valueId,
            array_keys($choices),
            array_values($choices),
        ));
    }

    /** @param array<string, string> $choices */
    private static function generatedId(array $choices): string
    {
        // Keep server-projected variants stable until the Panel persists the
        // random identifier created during an interactive edit.
        return substr(hash('sha256', self::choiceKey($choices)), 0, 16);
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $groups
     * @return list<array<string, string>>
     */
    private function combinations(array $groups): array
    {
        $combinations = [[]];

        foreach ($groups as $group) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($group['values'] as $value) {
                    $next[] = [...$combination, $group['id'] => $value['id']];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }
}
