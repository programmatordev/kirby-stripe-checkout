<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal Authoritative consent outcomes; null means Stripe returned no outcome. */
final readonly class ConsentSnapshot
{
    private const KEYS = ['termsOfService', 'promotions'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);

            return new self([
                'termsOfService' => self::outcome($data['termsOfService']),
                'promotions' => self::outcome($data['promotions']),
            ]);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    private static function outcome(mixed $value): ?string
    {
        return $value === null ? null : OrderData::text($value, 100);
    }
}
