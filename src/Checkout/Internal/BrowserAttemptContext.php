<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Cart\Internal\CartSnapshot;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;

/**
 * Captures browser authority independently of resolved purchase facts.
 *
 * @internal
 */
final readonly class BrowserAttemptContext
{
    private function __construct(
        private CheckoutSource $checkoutSource,
        private UiMode $uiMode,
        private ?string $languageCode,
        private ?string $userUuid,
        private string $csrfFingerprint,
        private ?string $cartId,
        private ?string $cartRevision,
    ) {}

    /** @param string $csrf The native App::csrf() value, never an unverified transport field. */
    public static function capture(
        CheckoutSource $checkoutSource,
        UiMode $uiMode,
        ?string $languageCode,
        ?string $userUuid,
        string $csrf,
        ?CartSnapshot $cart,
    ): self {
        if (($checkoutSource === CheckoutSource::Cart) !== ($cart !== null)) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        return new self(
            checkoutSource: $checkoutSource,
            uiMode: $uiMode,
            languageCode: $languageCode,
            userUuid: $userUuid,
            csrfFingerprint: hash('sha256', $csrf),
            cartId: $cart?->id(),
            cartRevision: $cart?->revision(),
        );
    }

    public function checkoutSource(): CheckoutSource
    {
        return $this->checkoutSource;
    }

    public function uiMode(): UiMode
    {
        return $this->uiMode;
    }

    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    public function userUuid(): ?string
    {
        return $this->userUuid;
    }

    public function guestReference(): ?string
    {
        // Kirby's CSRF value is native session state, not a caller-supplied identity.
        // Its rotation on logout also invalidates earlier guest actions.
        return $this->userUuid === null
            ? hash('sha256', 'stripe-checkout.guest:' . $this->csrfFingerprint)
            : null;
    }

    public function cartRevision(): ?string
    {
        return $this->cartRevision;
    }

    public function assertMatches(self $context): void
    {
        if ($this->userUuid !== $context->userUuid || hash_equals($this->csrfFingerprint, $context->csrfFingerprint) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        if ($this->checkoutSource !== $context->checkoutSource) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        if ($this->uiMode !== $context->uiMode) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        // An existing action keeps its original content, locale and navigation context.
        // Switching language may issue a new bootstrap, but cannot retarget this token.
        if ($this->languageCode !== $context->languageCode) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }

        if ($this->cartId !== $context->cartId || $this->cartRevision !== $context->cartRevision) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_CONFLICT);
        }
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'source' => $this->checkoutSource->value,
            'uiMode' => $this->uiMode->value,
            'languageCode' => $this->languageCode,
            'userUuid' => $this->userUuid,
            'csrfFingerprint' => $this->csrfFingerprint,
            'cartId' => $this->cartId,
            'cartRevision' => $this->cartRevision,
        ];
    }

    /** Revalidates only the browser contract when reading native session storage. */
    public static function fromArray(mixed $data): self
    {
        if (is_array($data) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $checkoutSource = CheckoutSource::tryFrom(self::requiredString($data, 'source'));
        $uiMode = UiMode::tryFrom(self::requiredString($data, 'uiMode'));

        if ($checkoutSource === null || $uiMode === null) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $cartId = self::nullableString($data, 'cartId');
        $cartRevision = self::nullableString($data, 'cartRevision');

        if ($checkoutSource === CheckoutSource::Cart && ($cartId === null || $cartRevision === null)) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if ($checkoutSource === CheckoutSource::Direct && ($cartId !== null || $cartRevision !== null)) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        return new self(
            checkoutSource: $checkoutSource,
            uiMode: $uiMode,
            languageCode: self::nullableString($data, 'languageCode'),
            userUuid: self::nullableString($data, 'userUuid'),
            csrfFingerprint: self::requiredString($data, 'csrfFingerprint'),
            cartId: $cartId,
            cartRevision: $cartRevision,
        );
    }

    /** @param array<array-key, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = self::nullableString($data, $key);

        return $value ?? throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
    }

    /** @param array<array-key, mixed> $data */
    private static function nullableString(array $data, string $key): ?string
    {
        if (array_key_exists($key, $data) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $value = $data[$key];

        if ($value !== null && (is_string($value) === false || $value === '')) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        return $value;
    }
}
