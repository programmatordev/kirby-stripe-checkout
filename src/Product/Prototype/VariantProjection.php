<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Prototype;

/**
 * Projects localized choices and rematches submitted selections server-side.
 *
 * @internal This prototype deliberately avoids exposing a public product API.
 */
final class VariantProjection
{
    public function __construct(
        private readonly VariantSchema $schema = new VariantSchema(),
    ) {}

    /**
     * @return array{groups: list<array{id: string, label: string, values: list<array{id: string, label: string}>}>, variants: list<array{id: string, choices: array<string, string>, enabled: bool}>}
     */
    public function project(mixed $canonicalValue, mixed $overlayValue = null): array
    {
        $canonical = $this->schema->canonical($canonicalValue);
        $localized = $this->schema->localized($canonical, $overlayValue);

        return [
            'groups' => $localized['groups'],
            'variants' => array_map(
                static fn(array $variant): array => [
                    'id' => $variant['id'],
                    'choices' => $variant['choices'],
                    'enabled' => $variant['enabled'],
                ],
                $localized['variants'],
            ),
        ];
    }

    /**
     * @param array<string, string> $submittedChoices
     * @return array{id: string, choices: array<string, string>, enabled: bool, sku: ?string, price: ?string, stripePriceId: ?string, requiresShipping: string}|null
     */
    public function match(mixed $canonicalValue, array $submittedChoices): ?array
    {
        $canonical = $this->schema->canonical($canonicalValue);
        ksort($submittedChoices);

        foreach ($canonical['variants'] as $variant) {
            $canonicalChoices = $variant['choices'];
            ksort($canonicalChoices);

            if (
                $variant['enabled'] === true
                && $canonicalChoices === $submittedChoices
            ) {
                return $variant;
            }
        }

        return null;
    }
}
