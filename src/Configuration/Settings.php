<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use LogicException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;

/**
 * Provides the immutable, sanitized public view of effective store settings.
 */
final class Settings
{
    /** @var array<string, Setting> */
    private readonly array $settings;

    /**
     * @internal Constructed from the public-setting whitelist.
     *
     * @param array<string, Setting> $settings
     */
    public function __construct(array $settings)
    {
        if (array_keys($settings) !== array_keys(Defaults::SETTINGS)) {
            throw new LogicException('The public Settings view contains an unexpected schema.');
        }

        $this->settings = $settings;
    }

    public function priceSource(): PriceSource
    {
        $value = $this->settings['priceSource']->value();

        if (is_string($value) === false) {
            throw new LogicException('The resolved priceSource must be a string.');
        }

        return PriceSource::from($value);
    }

    public function currency(): ?string
    {
        $value = $this->settings['currency']->value();

        if ($value !== null && is_string($value) === false) {
            throw new LogicException('The resolved currency must be a string or null.');
        }

        return $value;
    }

    public function defaultRequiresShipping(): ?bool
    {
        $value = $this->settings['defaultRequiresShipping']->value();

        if ($value !== null && is_bool($value) === false) {
            throw new LogicException('The resolved shipping default must be a boolean or null.');
        }

        return $value;
    }

    public function uiMode(): UiMode
    {
        $value = $this->settings['uiMode']->value();

        if (is_string($value) === false) {
            throw new LogicException('The resolved Checkout UI mode must be a string.');
        }

        return UiMode::from($value);
    }

    public function successDestination(): ?string
    {
        return $this->destination('successDestination');
    }

    public function cancelDestination(): ?string
    {
        return $this->destination('cancelDestination');
    }

    public function returnDestination(): ?string
    {
        return $this->destination('returnDestination');
    }

    public function setting(string $path): ?Setting
    {
        return $this->settings[$path] ?? null;
    }

    public function cleanupCreationFailures(): bool
    {
        return $this->settings['cleanupCreationFailures']->value() === true;
    }

    public function creationFailureRetentionDays(): int
    {
        return $this->retentionDays('creationFailureRetentionDays');
    }

    public function cleanupUnpaidOrders(): bool
    {
        return $this->settings['cleanupUnpaidOrders']->value() === true;
    }

    public function unpaidOrderRetentionDays(): int
    {
        return $this->retentionDays('unpaidOrderRetentionDays');
    }

    private function retentionDays(string $name): int
    {
        $value = $this->settings[$name]->value();

        if (is_int($value) === false || $value < 1) {
            throw new LogicException('Resolved retention days must be a positive integer.');
        }

        return $value;
    }

    private function destination(string $name): ?string
    {
        $value = $this->settings[$name]->value();

        if ($value !== null && is_string($value) === false) {
            throw new LogicException('A resolved Checkout destination must be a string or null.');
        }

        return $value;
    }

    /** @return array<string, Setting> */
    public function all(): array
    {
        return $this->settings;
    }
}
