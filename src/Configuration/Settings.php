<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use LogicException;

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
        if (array_keys($settings) !== ['priceSource']) {
            throw new LogicException('The public Settings view must contain only priceSource.');
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

    public function setting(string $path): ?Setting
    {
        return $this->settings[$path] ?? null;
    }

    /** @return array<string, Setting> */
    public function all(): array
    {
        return $this->settings;
    }
}
