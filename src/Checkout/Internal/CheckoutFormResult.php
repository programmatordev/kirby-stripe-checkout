<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutError;
use ProgrammatorDev\StripeCheckout\Translation\Catalogue;
use Throwable;

/** @internal Safe form failure and optional exact input for an existing action. */
final readonly class CheckoutFormResult
{
    public function __construct(
        private CheckoutError $error,
        private CheckoutFormResultContext $context,
        private ?CheckoutSubmissionInput $retryInput,
        private int $expiresAt,
    ) {}

    public function error(): CheckoutError
    {
        return $this->error;
    }

    public function context(): CheckoutFormResultContext
    {
        return $this->context;
    }

    public function retryInput(): ?CheckoutSubmissionInput
    {
        return $this->retryInput;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'error' => $this->error->toArray(),
            'context' => $this->context->toArray(),
            'retryInput' => $this->retryInput?->toSessionData(),
            'expiresAt' => $this->expiresAt,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        if (
            is_array($data) === false
            || array_diff(array_keys($data), ['error', 'context', 'retryInput', 'expiresAt']) !== []
            || is_array($data['error'] ?? null) === false
        ) {
            throw new InvalidArgumentException('Invalid Checkout form result.');
        }

        $error = $data['error'];

        if (array_diff(array_keys($error), ['code', 'message', 'retryable']) !== []) {
            throw new InvalidArgumentException('Invalid Checkout form result.');
        }

        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;
        $retryable = $error['retryable'] ?? null;
        $expiresAt = $data['expiresAt'] ?? null;

        if (is_string($code) === false || $code === '' || array_key_exists(Catalogue::PREFIX . $code, Catalogue::bundled()['en']) === false) {
            throw new InvalidArgumentException('Invalid Checkout form result.');
        }

        if (is_string($message) === false || $message === '') {
            throw new InvalidArgumentException('Invalid Checkout form result.');
        }

        if (is_bool($retryable) === false || is_int($expiresAt) === false || $expiresAt < 0) {
            throw new InvalidArgumentException('Invalid Checkout form result.');
        }

        try {
            $retryInput = ($data['retryInput'] ?? null) === null
                ? null
                : CheckoutSubmissionInput::fromSessionData($data['retryInput']);

            return new self(
                error: new CheckoutError($code, $message, $retryable),
                context: CheckoutFormResultContext::fromArray($data['context'] ?? null),
                retryInput: $retryInput,
                expiresAt: $expiresAt,
            );
        } catch (Throwable $error) {
            throw new InvalidArgumentException('Invalid Checkout form result.', previous: $error);
        }
    }
}
