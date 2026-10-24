<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Diagnostics;

/** Selected webhook endpoint facts needed by diagnostics and pagination. */
final readonly class WebhookEndpointRecord
{
    /** @param list<string> $enabledEvents */
    public function __construct(
        private string $endpointId,
        private string $url,
        private array $enabledEvents,
        private bool $liveMode,
        private ?string $apiVersion,
        private string $status,
    ) {}

    public function endpointId(): string
    {
        return $this->endpointId;
    }

    public function url(): string
    {
        return $this->url;
    }

    /** @return list<string> */
    public function enabledEvents(): array
    {
        return $this->enabledEvents;
    }

    public function liveMode(): bool
    {
        return $this->liveMode;
    }

    public function apiVersion(): ?string
    {
        return $this->apiVersion;
    }

    public function status(): string
    {
        return $this->status;
    }
}
