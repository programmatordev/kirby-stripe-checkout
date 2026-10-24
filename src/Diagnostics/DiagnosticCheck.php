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
     * @param list<string> $blockedOperations
     */
    public function __construct(
        private string $code,
        private DiagnosticStatus $status,
        private string $message,
        private array $context = [],
        private array $blockedOperations = [],
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function status(): DiagnosticStatus
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return $this->context;
    }

    /** @return list<string> */
    public function blockedOperations(): array
    {
        return $this->blockedOperations;
    }

    /** @return array{code: string, status: string, message: string, context: array<string, string>, blockedOperations: list<string>} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status->value,
            'message' => $this->message,
            'context' => $this->context,
            'blockedOperations' => $this->blockedOperations,
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
            $blockedOperations = OrderData::list($data['blockedOperations']);

            if ($status === null) {
                throw new InvalidArgumentException(self::INVALID_CHECK_MESSAGE);
            }

            return new self(
                code: OrderData::text($data['code']),
                status: $status,
                message: OrderData::text($data['message']),
                context: array_map(OrderData::string(...), $context),
                blockedOperations: array_map(OrderData::text(...), $blockedOperations),
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
            'message',
            'context',
            'blockedOperations',
        ];
    }
}
