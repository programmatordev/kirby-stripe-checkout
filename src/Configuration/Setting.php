<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Exposes one immutable, non-sensitive effective setting and its provenance.
 */
final class Setting
{
    /** @internal Constructed from validated configuration values. */
    public function __construct(
        private readonly mixed $settingValue,
        private readonly SettingSource $settingSource,
        private readonly bool $shadowed = false,
        private readonly mixed $pageShadow = null,
    ) {}

    public function value(): mixed
    {
        return $this->settingValue;
    }

    public function source(): SettingSource
    {
        return $this->settingSource;
    }

    public function isLocked(): bool
    {
        return $this->settingSource === SettingSource::Php;
    }

    public function hasShadowedValue(): bool
    {
        return $this->shadowed;
    }

    public function shadowedValue(): mixed
    {
        return $this->shadowed === true ? $this->pageShadow : null;
    }
}
