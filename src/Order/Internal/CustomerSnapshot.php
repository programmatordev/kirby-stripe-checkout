<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal Provider-neutral customer facts returned for one Checkout Session. */
final readonly class CustomerSnapshot
{
    private const KEYS = ['email', 'individualName', 'businessName', 'phone', 'taxIds'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            $taxIds = [];

            foreach (OrderData::list($data['taxIds']) as $taxId) {
                $taxId = OrderData::map($taxId);
                OrderData::validateAllowedKeys($taxId, ['type', 'value']);
                OrderData::validateRequiredKeys($taxId, ['type', 'value']);
                $taxIds[] = [
                    'type' => OrderData::text($taxId['type'], 100),
                    'value' => OrderData::nullableSingleLine($taxId['value'], 255),
                ];
            }

            return new self([
                'email' => OrderData::nullableSingleLine($data['email'], 320),
                'individualName' => OrderData::nullableSingleLine($data['individualName'], 255),
                'businessName' => OrderData::nullableSingleLine($data['businessName'], 255),
                'phone' => OrderData::nullableSingleLine($data['phone'], 255),
                'taxIds' => $taxIds,
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
