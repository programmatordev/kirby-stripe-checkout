<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;

/** @internal Browser and initiating-page binding for one redirected form result. */
final readonly class CheckoutFormResultContext
{
    private function __construct(
        private CheckoutSource $checkoutSource,
        private UiMode $uiMode,
        private ?string $languageCode,
        private ?string $userUuid,
        private ?string $guestReference,
        private string $initiatingUrl,
    ) {}

    public static function fromAttempt(BrowserAttempt $attempt): self
    {
        $context = $attempt->context();

        return new self(
            checkoutSource: $context->checkoutSource(),
            uiMode: $context->uiMode(),
            languageCode: $context->languageCode(),
            userUuid: $context->userUuid(),
            guestReference: $context->guestReference(),
            initiatingUrl: $attempt->initiatingUrl(),
        );
    }

    public function matches(BrowserAttemptContext $context, string $initiatingUrl): bool
    {
        // Cart revision belongs to exact retry authorization, not error presentation.
        // A changed cart may receive a fresh action while still showing the preceding failure.
        return $this->checkoutSource === $context->checkoutSource()
            && $this->uiMode === $context->uiMode()
            && $this->languageCode === $context->languageCode()
            && $this->userUuid === $context->userUuid()
            && $this->guestReference === $context->guestReference()
            && hash_equals($this->initiatingUrl, $initiatingUrl);
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'source' => $this->checkoutSource->value,
            'uiMode' => $this->uiMode->value,
            'languageCode' => $this->languageCode,
            'userUuid' => $this->userUuid,
            'guestReference' => $this->guestReference,
            'initiatingUrl' => $this->initiatingUrl,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        if (
            is_array($data) === false
            || array_diff(array_keys($data), ['source', 'uiMode', 'languageCode', 'userUuid', 'guestReference', 'initiatingUrl']) !== []
        ) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        $checkoutSource = is_string($data['source'] ?? null) ? CheckoutSource::tryFrom($data['source']) : null;
        $uiMode = is_string($data['uiMode'] ?? null) ? UiMode::tryFrom($data['uiMode']) : null;
        $languageCode = self::nullableString($data, 'languageCode');
        $userUuid = self::nullableString($data, 'userUuid');
        $guestReference = self::nullableString($data, 'guestReference');
        $initiatingUrl = $data['initiatingUrl'] ?? null;

        if ($checkoutSource === null || $uiMode === null) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        if (($userUuid === null) === ($guestReference === null)) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        if (is_string($initiatingUrl) === false || CheckoutUrlValidator::isPersistedDestination($initiatingUrl, false) === false) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        return new self(
            checkoutSource: $checkoutSource,
            uiMode: $uiMode,
            languageCode: $languageCode,
            userUuid: $userUuid,
            guestReference: $guestReference,
            initiatingUrl: $initiatingUrl,
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (array_key_exists($key, $data) === false) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        $value = $data[$key];

        if ($value !== null && (is_string($value) === false || $value === '')) {
            throw new InvalidArgumentException('Invalid Checkout form result context.');
        }

        return $value;
    }
}
