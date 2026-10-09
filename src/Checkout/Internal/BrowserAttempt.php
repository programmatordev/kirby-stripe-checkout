<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;

/**
 * An issued action and its first accepted purchase binding, separate from Order/provider facts.
 *
 * @internal
 */
final readonly class BrowserAttempt
{
    public function __construct(
        private AttemptToken $token,
        private BrowserAttemptContext $context,
        private string $initiatingUrl,
        private int $issuedAt,
        private ?string $bindingFingerprint = null,
        private ?int $boundAt = null,
    ) {}

    public function token(): AttemptToken
    {
        return $this->token;
    }

    public function context(): BrowserAttemptContext
    {
        return $this->context;
    }

    public function initiatingUrl(): string
    {
        return $this->initiatingUrl;
    }

    public function issuedAt(): int
    {
        return $this->issuedAt;
    }

    public function boundAt(): ?int
    {
        return $this->boundAt;
    }

    public function bind(AttemptBinding $binding, int $boundAt): self
    {
        $binding->assertCompatibleBrowserContext($this->context);

        if ($this->bindingFingerprint !== null) {
            $binding->assertMatchesFingerprint($this->bindingFingerprint);

            // Preserve first acceptance: an identical submission cannot refresh browser retention.
            return $this;
        }

        return new self(
            token: $this->token,
            context: $this->context,
            initiatingUrl: $this->initiatingUrl,
            issuedAt: $this->issuedAt,
            bindingFingerprint: $binding->fingerprint(),
            boundAt: $boundAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // The transport token is supplied at lookup; native storage needs only its hash as the map key.
        return [
            'context' => $this->context->toArray(),
            'initiatingUrl' => $this->initiatingUrl,
            'issuedAt' => $this->issuedAt,
            'bindingFingerprint' => $this->bindingFingerprint,
            'boundAt' => $this->boundAt,
        ];
    }

    public static function fromArray(AttemptToken $token, mixed $data): self
    {
        if (is_array($data) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $initiatingUrl = $data['initiatingUrl'] ?? null;
        $issuedAt = $data['issuedAt'] ?? null;

        if (is_string($initiatingUrl) === false || CheckoutUrlValidator::isPersistedDestination($initiatingUrl, false) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if (is_int($issuedAt) === false || $issuedAt < 0) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if (array_key_exists('bindingFingerprint', $data) === false || array_key_exists('boundAt', $data) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $bindingFingerprint = $data['bindingFingerprint'];
        $boundAt = $data['boundAt'];

        if ($bindingFingerprint !== null && (is_string($bindingFingerprint) === false || $bindingFingerprint === '')) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if ($boundAt !== null && (is_int($boundAt) === false || $boundAt < $issuedAt)) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        // An incomplete binding record must not be interpreted as an unused form.
        if (($bindingFingerprint === null) !== ($boundAt === null)) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        return new self(
            token: $token,
            context: BrowserAttemptContext::fromArray($data['context'] ?? null),
            initiatingUrl: $initiatingUrl,
            issuedAt: $issuedAt,
            bindingFingerprint: $bindingFingerprint,
            boundAt: $boundAt,
        );
    }
}
