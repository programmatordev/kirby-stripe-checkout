<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use LogicException;
use SensitiveParameter;

/**
 * Keeps validated Stripe credentials inside the internal configuration graph.
 *
 * @internal
 */
final class StripeConfiguration
{
    public function __construct(
        #[SensitiveParameter]
        private readonly ?string $secretKey,
        #[SensitiveParameter]
        private readonly ?string $publishableKey,
        #[SensitiveParameter]
        private readonly ?string $webhookSecret,
    ) {}

    public function secretKey(): ?string
    {
        return $this->secretKey;
    }

    public function publishableKey(): ?string
    {
        return $this->publishableKey;
    }

    public function webhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function hasSecretKey(): bool
    {
        return $this->secretKey !== null;
    }

    public function hasPublishableKey(): bool
    {
        return $this->publishableKey !== null;
    }

    public function hasWebhookSecret(): bool
    {
        return $this->webhookSecret !== null;
    }

    public function serverMode(): CredentialMode
    {
        return $this->detectMode($this->secretKey, ['sk', 'rk']);
    }

    public function publishableMode(): CredentialMode
    {
        return $this->detectMode($this->publishableKey, ['pk']);
    }

    /** @return array<string, bool|string> */
    public function __debugInfo(): array
    {
        return [
            'secretKeyConfigured' => $this->hasSecretKey(),
            'publishableKeyConfigured' => $this->hasPublishableKey(),
            'webhookSecretConfigured' => $this->hasWebhookSecret(),
            'serverMode' => $this->serverMode()->value,
            'publishableMode' => $this->publishableMode()->value,
        ];
    }

    /** Prevents credential-bearing configuration from being serialized. */
    public function __serialize(): array
    {
        throw new LogicException('Stripe credentials cannot be serialized.');
    }

    /**
     * @param list<string> $prefixes
     */
    private function detectMode(?string $key, array $prefixes): CredentialMode
    {
        if ($key === null) {
            return CredentialMode::Unknown;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix . '_test_')) {
                return CredentialMode::Test;
            }

            if (str_starts_with($key, $prefix . '_live_')) {
                return CredentialMode::Live;
            }
        }

        return CredentialMode::Unknown;
    }
}
