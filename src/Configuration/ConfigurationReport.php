<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use LogicException;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;

/**
 * Preserves a non-throwing configuration result for later diagnostics edges.
 *
 * @internal
 */
final class ConfigurationReport
{
    private function __construct(
        private readonly ?Configuration $configuration,
        private readonly ?ConfigurationException $error,
    ) {}

    public static function valid(Configuration $configuration): self
    {
        return new self($configuration, null);
    }

    public static function invalid(ConfigurationException $error): self
    {
        return new self(null, $error);
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }

    public function error(): ?ConfigurationException
    {
        return $this->error;
    }

    public function configuration(): ?Configuration
    {
        return $this->configuration;
    }

    public function configurationOrFail(): Configuration
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->configuration
            ?? throw new LogicException('A valid configuration report requires a result.');
    }
}
