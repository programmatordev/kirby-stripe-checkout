<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Plugin;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationReport;
use ProgrammatorDev\StripeCheckout\Configuration\ConfigurationResolver;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use ProgrammatorDev\StripeCheckout\Kirby\SettingsPageStore;

/**
 * Builds and owns the service graph for one public plugin operation.
 *
 * @internal
 */
final class RuntimeFactory
{
    private ?ConfigurationReport $configurationReport = null;

    public function __construct(
        private readonly App $kirby,
    ) {}

    public function settings(): Settings
    {
        return $this->configurationReport()
            ->configurationOrFail()
            ->settings();
    }

    public function configurationReport(): ConfigurationReport
    {
        /** @var array<string, mixed> $options */
        $options = $this->kirby->options();

        if ($this->configurationReport !== null) {
            return $this->configurationReport;
        }

        $resolver = new ConfigurationResolver();
        $phpReport = $resolver->resolve($options);

        if ($phpReport->isValid() === false) {
            return $this->configurationReport = $phpReport;
        }

        try {
            $pageSettings = (new SettingsPageStore($this->kirby))->settings();
        } catch (ConfigurationException $error) {
            return $this->configurationReport = ConfigurationReport::invalid($error);
        }

        return $this->configurationReport = $resolver->resolve(
            $options,
            $pageSettings,
        );
    }
}
