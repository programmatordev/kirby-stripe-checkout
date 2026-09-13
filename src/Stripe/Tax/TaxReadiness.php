<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use Kirby\Cache\Cache;
use ProgrammatorDev\StripeCheckout\Exception\ConfigurationException;
use Throwable;

/**
 * Reads account readiness once per operation, with a one-hour native cache.
 * Unlike catalogue data, expired readiness cannot fall back to stale facts.
 *
 * @internal
 */
final class TaxReadiness
{
    private ?TaxSettings $settings = null;
    private ?ConfigurationException $failure = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly ?TaxProviderInterface $provider,
        private readonly string $cacheKey,
        private readonly ?bool $liveMode = null,
    ) {}

    public function cached(): ?TaxSettings
    {
        // Kirby's get() excludes expired entries; do not bypass native expiry
        // to recover readiness from an older successful read.
        $cached = $this->cache->get($this->cacheKey);

        if (
            is_array($cached) === false
            || is_string($cached['status'] ?? null) === false
            || is_bool($cached['liveMode'] ?? null) === false
            || array_key_exists('defaultTaxCode', $cached) === false
            || array_key_exists('defaultTaxBehavior', $cached) === false
            || ($cached['defaultTaxCode'] !== null && is_string($cached['defaultTaxCode']) === false)
            || ($cached['defaultTaxBehavior'] !== null && is_string($cached['defaultTaxBehavior']) === false)
            || is_array($cached['missingFields'] ?? null) === false
            || is_int($cached['readAt'] ?? null) === false
        ) {
            return null;
        }

        try {
            $settings = new TaxSettings(
                status: $cached['status'],
                liveMode: $cached['liveMode'],
                defaultTaxCode: $cached['defaultTaxCode'],
                defaultTaxBehavior: $cached['defaultTaxBehavior'],
                missingFields: $cached['missingFields'],
                readAt: $cached['readAt'],
            );
            $this->assertCredentialMode($settings);

            return $settings;
        } catch (Throwable) {
            return null;
        }
    }

    public function load(): TaxSettings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        if ($this->failure !== null) {
            // Suppress duplicate requests within this operation only. A new
            // runtime may retry; failures are not persisted in the shared cache.
            throw $this->failure;
        }

        if (($cached = $this->cached()) !== null) {
            return $this->settings = $cached;
        }

        try {
            if ($this->provider === null) {
                throw new ConfigurationException('configuration.credential_missing', 'stripe.secretKey');
            }

            try {
                $record = $this->provider->retrieveSettings();
            } catch (Throwable $error) {
                throw new ConfigurationException('tax.settings_unavailable', 'stripe.taxSettings', $error);
            }

            $settings = new TaxSettings(
                status: $record->status,
                liveMode: $record->liveMode,
                defaultTaxCode: $record->defaultTaxCode,
                defaultTaxBehavior: $record->defaultTaxBehavior,
                missingFields: $record->missingFields,
                readAt: time(),
            );
            $this->assertCredentialMode($settings);
            $this->cache->set($this->cacheKey, $settings->toArray(), 60);

            return $this->settings = $settings;
        } catch (ConfigurationException $error) {
            $this->failure = $error;

            throw $error;
        }
    }

    public function requireReady(
        bool $requiresDefaultTaxCode = false,
        bool $requiresDefaultTaxBehavior = false,
    ): TaxSettings {
        $settings = $this->load();

        // Active confirms required account information, not registrations or
        // whether a particular destination should produce a non-zero amount.
        // https://docs.stripe.com/api/tax/settings/object?query=status
        if ($settings->isActive() === false) {
            throw new ConfigurationException('tax.settings_pending', 'stripe.taxSettings');
        }

        // Products can supply their own code and behavior, so absent account
        // defaults block readiness only when the request needs those fallbacks.
        if ($requiresDefaultTaxCode && $settings->defaultTaxCode() === null) {
            throw new ConfigurationException('tax.default_code_missing', 'stripe.taxSettings.defaults.taxCode');
        }

        if ($requiresDefaultTaxBehavior && $settings->defaultTaxBehavior() === null) {
            throw new ConfigurationException('tax.default_behavior_missing', 'stripe.taxSettings.defaults.taxBehavior');
        }

        return $settings;
    }

    private function assertCredentialMode(TaxSettings $settings): void
    {
        if ($this->liveMode !== null && $settings->liveMode() !== $this->liveMode) {
            throw new ConfigurationException('tax.settings_mode_mismatch', 'stripe.taxSettings');
        }
    }
}
