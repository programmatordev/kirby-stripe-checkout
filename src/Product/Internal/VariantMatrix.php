<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Internal;

use Closure;

/**
 * Reconciles generated product combinations without discarding merchant data.
 *
 * @internal
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
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $options
     * @param list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}> $variants
     * @return list<array{id: string, selectedOptions: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}>
     */
    public function reconcile(array $options, array $variants): array
    {
        if ($options === []) {
            return [];
        }

        $existing = [];

        foreach ($variants as $variant) {
            $existing[self::optionCombinationKey($variant['selectedOptions'])] = $variant;
        }

        $reconciled = [];

        foreach ($this->combinations($options) as $selectedOptions) {
            $key = self::optionCombinationKey($selectedOptions);
            $variant = $existing[$key] ?? null;

            $reconciled[] = $variant ?? [
                'id' => ($this->idGenerator)($selectedOptions),
                'selectedOptions' => $selectedOptions,
                'enabled' => true,
                'sku' => null,
                'price' => null,
                'stripePriceId' => null,
                'requiresShipping' => 'inherit',
            ];
        }

        return $reconciled;
    }

    /** @param array<string, string> $selectedOptions */
    public static function optionCombinationKey(array $selectedOptions): string
    {
        ksort($selectedOptions);

        return implode('|', array_map(
            static fn(string $optionId, string $valueId): string => $optionId . ':' . $valueId,
            array_keys($selectedOptions),
            array_values($selectedOptions),
        ));
    }

    /** @param array<string, string> $selectedOptions */
    private static function generatedId(array $selectedOptions): string
    {
        // Keep server-projected variants stable until the Panel persists the
        // random identifier created during an interactive edit.
        return substr(hash('sha256', self::optionCombinationKey($selectedOptions)), 0, 16);
    }

    /**
     * @param list<array{id: string, label: string, values: list<array{id: string, label: string}>}> $options
     * @return list<array<string, string>>
     */
    private function combinations(array $options): array
    {
        $combinations = [[]];

        foreach ($options as $option) {
            $next = [];

            foreach ($combinations as $combination) {
                foreach ($option['values'] as $value) {
                    $next[] = [...$combination, $option['id'] => $value['id']];
                }
            }

            $combinations = $next;
        }

        return $combinations;
    }
}
