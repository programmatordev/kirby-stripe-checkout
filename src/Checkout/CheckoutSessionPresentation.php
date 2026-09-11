<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

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
            || ($redirectUrl !== null && $this->isHttpUrl($redirectUrl) === false)
            || (
                $clientSecret !== null
                && (
                    trim($clientSecret) === ''
                    || trim($clientSecret) !== $clientSecret
                    || strlen($clientSecret) > 2048
                    || mb_check_encoding($clientSecret, 'UTF-8') === false
                    || preg_match('/[\x00-\x1F\x7F]/', $clientSecret) === 1
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

    private function isHttpUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
