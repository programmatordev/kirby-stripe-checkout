<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use DateTimeImmutable;
use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/**
 * Immutable, credential-free context exposed to Session request customization.
 */
final readonly class SessionRequestContext
{
    /** @internal Constructed after Checkout context normalization. */
    public function __construct(
        private OrderCreationContext $order,
        private UiMode $uiMode,
        private string $locale,
        private DateTimeImmutable $expiresAt,
        private string $originUrl,
        private string $successDestination,
        private string $cancelDestination,
        private string $returnDestination,
    ) {
        if (
            $order->uiMode() !== $uiMode->value
            || $locale === ''
            || $originUrl === ''
            || $successDestination === ''
            || $cancelDestination === ''
            || $returnDestination === ''
        ) {
            throw new InvalidArgumentException('The Session request context is inconsistent.');
        }
    }

    public function order(): OrderCreationContext
    {
        return $this->order;
    }

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    public function languageCode(): ?string
    {
        return $this->order->languageCode();
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function originUrl(): string
    {
        return $this->originUrl;
    }

    public function successDestination(): string
    {
        return $this->successDestination;
    }

    public function cancelDestination(): string
    {
        return $this->cancelDestination;
    }

    public function returnDestination(): string
    {
        return $this->returnDestination;
    }
}
