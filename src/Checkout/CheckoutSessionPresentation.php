<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

/**
 * Ephemeral projection of the validated Session and persisted Order identity.
 * Provider validation belongs to CheckoutSessionFactory before association or reuse.
 *
 * @internal
 */
final readonly class CheckoutSessionPresentation
{
    public function __construct(
        private UiMode $uiMode,
        private string $orderPageUuid,
        private bool $reused,
        private ?string $redirectUrl,
        private ?string $clientSecret,
    ) {}

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    public function orderPageUuid(): string
    {
        return $this->orderPageUuid;
    }

    public function isReused(): bool
    {
        return $this->reused;
    }

    public function redirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function clientSecret(): ?string
    {
        return $this->clientSecret;
    }
}
