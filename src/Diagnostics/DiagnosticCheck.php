<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Diagnostics;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use Throwable;

/**
 * One sanitized diagnostic fact with its stable code and affected operations.
 * Affected operations are explanatory metadata and never gate runtime work.
 */
final readonly class DiagnosticCheck
{
    private const INVALID_CHECK_MESSAGE = 'Invalid diagnostic check.';

    /**
     * @param array<string, string> $context
     * @param list<string> $affectedOperations
     */
    public function __construct(
        private string $code,
        private DiagnosticStatus $status,
        private string $messageKey,
        private array $context = [],
        private array $affectedOperations = [],
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function status(): DiagnosticStatus
    {
        return $this->status;
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return list<string> */
    public function affectedOperations(): array
    {
        return $this->affectedOperations;
    }

    /** @return array{code: string, status: string, messageKey: string, context: array<string, string>, affectedOperations: list<string>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'messageKey' => $this->messageKey,
            'context' => $this->context,
            'affectedOperations' => $this->affectedOperations,
        ];
    }

    public static function fromArray(mixed $data): self
    {
        try {
            if (is_array($data) === false) {
                throw new InvalidArgumentException(self::INVALID_CHECK_MESSAGE);
            }

            $keys = self::keys();

            if (array_diff(array_keys($data), $keys) !== [] || array_diff($keys, array_keys($data)) !== []) {
                throw new InvalidArgumentException(self::INVALID_CHECK_MESSAGE);
            }

            $status = is_string($data['status']) ? DiagnosticStatus::tryFrom($data['status']) : null;
            $context = OrderData::map($data['context']);
            $affectedOperations = OrderData::list($data['affectedOperations']);

            if ($status === null) {
                throw new InvalidArgumentException(self::INVALID_CHECK_MESSAGE);
            }

            return new self(
                code: OrderData::text($data['code']),
                status: $status,
                messageKey: OrderData::text($data['messageKey']),
                context: array_map(OrderData::string(...), $context),
                affectedOperations: array_map(OrderData::text(...), $affectedOperations),
            );
        } catch (Throwable $error) {
            throw new InvalidArgumentException(self::INVALID_CHECK_MESSAGE, previous: $error);
        }
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return [
            'code',
            'status',
            'messageKey',
            'context',
            'affectedOperations',
        ];
    }
}
