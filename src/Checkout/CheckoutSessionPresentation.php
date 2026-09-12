<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\Internal\CheckoutUrlValidator;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** @internal Ephemeral hosted redirect or embedded client secret returned after orchestration. */
final readonly class CheckoutSessionPresentation
{
    public function __construct(
        private UiMode $uiMode,
        private string $orderPageUuid,
        private bool $reused,
        private ?string $redirectUrl,
        private ?string $clientSecret,
    ) {
        OrderData::uuid($orderPageUuid);

        if (
            ($uiMode === UiMode::Hosted) !== ($redirectUrl !== null)
            || ($uiMode === UiMode::Embedded) !== ($clientSecret !== null)
            || ($redirectUrl !== null && CheckoutUrlValidator::isHostedPresentation($redirectUrl) === false)
            || (
                $clientSecret !== null
                && (
                    trim($clientSecret) === ''
                    || trim($clientSecret) !== $clientSecret
                    || strlen($clientSecret) > 2048
                    || TextValidator::isSingleLine($clientSecret) === false
                )
            )
        ) {
            throw new InvalidArgumentException('The Checkout Session presentation is inconsistent.');
        }
    }

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
