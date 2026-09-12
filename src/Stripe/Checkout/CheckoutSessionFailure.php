<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;

/** @internal Sanitized provider outcome and its independent retry decision. */
final readonly class CheckoutSessionFailure
{
    public function __construct(
        private CheckoutSessionFailureType $type,
        private bool $retryable,
        private ?string $requestId = null,
        private ?string $providerCode = null,
        private ?string $providerType = null,
    ) {
        if ($retryable && in_array($type, [CheckoutSessionFailureType::Rejected, CheckoutSessionFailureType::Incompatible], true)) {
            throw new InvalidArgumentException('A definitive provider outcome cannot be retryable.');
        }

        foreach ([$requestId, $providerCode, $providerType] as $value) {
            if ($value !== null && self::isSafeText($value) === false) {
                throw new InvalidArgumentException('Provider failure facts must be safe text.');
            }
        }
    }

    public static function fromProvider(
        CheckoutSessionFailureType $type,
        bool $retryable,
        mixed $requestId = null,
        mixed $providerCode = null,
        mixed $providerType = null,
    ): self {
        return new self(
            type: $type,
            retryable: $retryable,
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
        return $this->retryable;
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
            && TextValidator::isSingleLine($value)
            && mb_strlen($value) <= 255;
    }
}
