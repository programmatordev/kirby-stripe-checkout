<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutErrorCode;
use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;

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
        private ?CheckoutSubmissionInput $acceptedInput = null,
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

    public function acceptedInput(): ?CheckoutSubmissionInput
    {
        return $this->acceptedInput;
    }

    public function bind(
        AttemptBinding $binding,
        CheckoutSubmissionInput $acceptedInput,
        int $boundAt,
    ): self {
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
            acceptedInput: $this->context->uiMode() === UiMode::Embedded
                ? $acceptedInput
                : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        // An accepted embedded action must retain its exact normalized input so a return can resume
        // the same Session. Unused actions still keep the raw transport token only in the browser.
        return [
            'pageUuid' => OrderData::pageUuid($this->token->orderUuid()),
            'context' => $this->context->toArray(),
            'initiatingUrl' => $this->initiatingUrl,
            'issuedAt' => $this->issuedAt,
            'bindingFingerprint' => $this->bindingFingerprint,
            'boundAt' => $this->boundAt,
            'acceptedInput' => $this->acceptedInput?->toSessionData(),
        ];
    }

    public static function fromArray(AttemptToken $token, mixed $data): self
    {
        if (is_array($data) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if (($data['pageUuid'] ?? null) !== OrderData::pageUuid($token->orderUuid())) {
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

        if (
            array_key_exists('bindingFingerprint', $data) === false
            || array_key_exists('boundAt', $data) === false
            || array_key_exists('acceptedInput', $data) === false
        ) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        $bindingFingerprint = $data['bindingFingerprint'];
        $boundAt = $data['boundAt'];
        $acceptedInputData = $data['acceptedInput'];
        $context = BrowserAttemptContext::fromArray($data['context'] ?? null);

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

        if ($bindingFingerprint === null && $acceptedInputData !== null) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if ($bindingFingerprint !== null && $context->uiMode() === UiMode::Embedded && $acceptedInputData === null) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if ($context->uiMode() !== UiMode::Embedded && $acceptedInputData !== null) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        try {
            $acceptedInput = $acceptedInputData === null
                ? null
                : CheckoutSubmissionInput::fromSessionData($acceptedInputData);
        } catch (\Throwable $error) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, previous: $error);
        }

        if ($acceptedInput !== null && hash_equals($token->value(), $acceptedInput->attemptToken()->value()) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        return new self(
            token: $token,
            context: $context,
            initiatingUrl: $initiatingUrl,
            issuedAt: $issuedAt,
            bindingFingerprint: $bindingFingerprint,
            boundAt: $boundAt,
            acceptedInput: $acceptedInput,
        );
    }

    /** Revalidates an accepted attempt when the raw token is not retained. */
    public static function boundContextFromArray(string $pageUuid, mixed $data): BrowserAttemptContext
    {
        if (is_array($data) === false || ($data['pageUuid'] ?? null) !== $pageUuid) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        try {
            OrderData::uuid($pageUuid);
        } catch (\Throwable $error) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID, previous: $error);
        }

        $initiatingUrl = $data['initiatingUrl'] ?? null;
        $issuedAt = $data['issuedAt'] ?? null;
        $bindingFingerprint = $data['bindingFingerprint'] ?? null;
        $boundAt = $data['boundAt'] ?? null;

        if (is_string($initiatingUrl) === false || CheckoutUrlValidator::isPersistedDestination($initiatingUrl, false) === false) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if (is_int($issuedAt) === false || is_int($boundAt) === false || $boundAt < $issuedAt) {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        if (is_string($bindingFingerprint) === false || $bindingFingerprint === '') {
            throw new CheckoutInputException(CheckoutErrorCode::ATTEMPT_TOKEN_INVALID);
        }

        return BrowserAttemptContext::fromArray($data['context'] ?? null);
    }
}
