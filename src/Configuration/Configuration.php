<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use Closure;
use ProgrammatorDev\StripeCheckout\Checkout\SessionRequestFactoryInterface;

/**
 * Carries normalized configuration inside one operation-scoped service graph.
 *
 * @internal
 */
final class Configuration
{
    /**
     * @param array<string, array<string, string>> $translations
     * @param array{intervalHours: int, batchSize: int} $housekeeping
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly StripeConfiguration $stripe,
        private readonly array $translations,
        private readonly ProductConfiguration $products,
        private readonly bool $cartEnabled,
        private readonly array $housekeeping,
        private readonly SessionRequestFactoryInterface|Closure|null $sessionRequestFactory,
    ) {}

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function cartEnabled(): bool
    {
        return $this->cartEnabled;
    }

    /** @return array{intervalHours: int, batchSize: int} */
    public function housekeeping(): array
    {
        return $this->housekeeping;
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

    public function sessionRequestFactory(): SessionRequestFactoryInterface|Closure|null
    {
        return $this->sessionRequestFactory;
    }
}
