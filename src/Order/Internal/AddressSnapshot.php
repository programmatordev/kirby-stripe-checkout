<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal Provider-neutral billing or shipping address retained with an order. */
final readonly class AddressSnapshot
{
    private const KEYS = ['name', 'line1', 'line2', 'postalCode', 'city', 'state', 'country'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);

            $country = OrderData::nullableSingleLine($data['country'], 2);

            if ($country !== null && preg_match('/\A[A-Z]{2}\z/D', $country) !== 1) {
                throw new OrderDataException();
            }

            return new self([
                'name' => OrderData::nullableSingleLine($data['name'], 255),
                'line1' => OrderData::nullableSingleLine($data['line1'], 255),
                'line2' => OrderData::nullableSingleLine($data['line2'], 255),
                'postalCode' => OrderData::nullableSingleLine($data['postalCode'], 255),
                'city' => OrderData::nullableSingleLine($data['city'], 255),
                'state' => OrderData::nullableSingleLine($data['state'], 255),
                'country' => $country,
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
