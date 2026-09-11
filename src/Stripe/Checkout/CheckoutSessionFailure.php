<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use InvalidArgumentException;

/** @internal Sanitized provider failure facts that are safe to persist and report. */
final readonly class CheckoutSessionFailure
{
    public function __construct(
        private CheckoutSessionFailureType $type,
        private ?string $requestId = null,
        private ?string $providerCode = null,
        private ?string $providerType = null,
    ) {
        foreach ([$requestId, $providerCode, $providerType] as $value) {
            if ($value !== null && self::isSafeText($value) === false) {
                throw new InvalidArgumentException('Provider failure facts must be safe text.');
            }
        }
    }

    public static function fromProvider(
        CheckoutSessionFailureType $type,
        mixed $requestId = null,
        mixed $providerCode = null,
        mixed $providerType = null,
    ): self {
        return new self(
            type: $type,
            requestId: self::safeText($requestId),
            providerCode: self::safeText($providerCode),
            providerType: self::safeText($providerType),
        );
    }

    public function type(): CheckoutSessionFailureType
    {
        return $this->type;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }

    public function providerType(): ?string
    {
        return $this->providerType;
    }

    public function isRetryable(): bool
    {
        return in_array($this->type, [
            CheckoutSessionFailureType::Retryable,
            CheckoutSessionFailureType::Unavailable,
            CheckoutSessionFailureType::Uncertain,
        ], true);
    }

    /** @return array{type: string, requestId: ?string, providerCode: ?string, providerType: ?string, retryable: bool} */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'requestId' => $this->requestId,
            'providerCode' => $this->providerCode,
            'providerType' => $this->providerType,
            'retryable' => $this->isRetryable(),
        ];
    }

    private static function safeText(mixed $value): ?string
    {
        if (
            is_string($value) === false
            || self::isSafeText($value) === false
        ) {
            return null;
        }

        return $value;
    }

    private static function isSafeText(string $value): bool
    {
        return trim($value) !== ''
            && trim($value) === $value
            && mb_strlen($value) <= 255
            && mb_check_encoding($value, 'UTF-8')
            && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 1;
    }
}
