<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Collection\CustomField;
use ProgrammatorDev\StripeCheckout\Collection\CustomFieldType;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal One presented Checkout custom field and its authoritative answer. */
final readonly class CustomFieldSnapshot
{
    private const KEYS = ['key', 'type', 'label', 'required', 'configured', 'answered', 'value'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            $key = OrderData::text($data['key'], CustomField::MAX_KEY_LENGTH);
            $type = CustomFieldType::from(OrderData::text($data['type'], 20));
            $answered = OrderData::boolean($data['answered']);
            $value = OrderData::nullableSingleLine($data['value'], 255);
            $configured = OrderData::boolean($data['configured']);

            if (
                preg_match('/\A[a-z0-9]+\z/D', $key) !== 1
                || $configured === false
                || $answered !== ($value !== null)
                || $type === CustomFieldType::Numeric && $value !== null && preg_match('/\A[0-9]+\z/D', $value) !== 1
            ) {
                throw new OrderDataException();
            }

            return new self([
                'key' => $key,
                'type' => $type->value,
                'label' => OrderData::text($data['label'], 50),
                'required' => OrderData::boolean($data['required']),
                'configured' => $configured,
                'answered' => $answered,
                'value' => $value,
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
}
