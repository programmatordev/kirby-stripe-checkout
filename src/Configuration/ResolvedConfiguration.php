<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Carries normalized configuration inside one operation-scoped service graph.
 *
 * @internal
 */
final class ResolvedConfiguration
{
    /**
     * @param array<string, array<string, string>> $translations
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly StripeConfiguration $stripe,
        private readonly array $translations,
        private readonly ProductConfiguration $products,
        private readonly bool $cartEnabled = true,
    ) {}

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function cartEnabled(): bool
    {
        return $this->cartEnabled;
    }

    public function stripe(): StripeConfiguration
    {
        return $this->stripe;
    }

    public function products(): ProductConfiguration
    {
        return $this->products;
    }

    /** @return array<string, array<string, string>> */
    public function translations(): array
    {
        return $this->translations;
    }
}
