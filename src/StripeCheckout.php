<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Plugin\RuntimeFactory;

/**
 * Provides the immutable, Site-scoped entry point for plugin developers.
 */
final class StripeCheckout
{
    /**
     * Keeps every operation tied to the Site's active App instead of relying
     * on ambient global state.
     *
     * @internal Constructed by the registered Kirby Site method.
     */
    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return (new RuntimeFactory($this->kirby))->settings();
    }
}
