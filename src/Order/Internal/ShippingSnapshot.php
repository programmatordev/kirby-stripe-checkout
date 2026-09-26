<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimate;
use ProgrammatorDev\StripeCheckout\Shipping\DeliveryEstimateUnit;
use Throwable;

/** @internal Selected shipping facts and exact amounts returned by the provider. */
final readonly class ShippingSnapshot
{
    private const KEYS = [
        'optionKey',
        'quoteFingerprint',
        'label',
        'currency',
        'subtotal',
        'providerSubtotal',
        'tax',
        'providerTax',
        'total',
        'providerTotal',
        'deliveryEstimate',
        'taxBehavior',
        'taxCode',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            $optionKey = OrderData::text($data['optionKey'], 64);
            $quoteFingerprint = OrderData::text($data['quoteFingerprint'], 64);
            $label = OrderData::text($data['label'], 100);
            $currency = OrderData::text($data['currency'], 3);

            if (
                preg_match('/\A[a-z0-9_-]{1,64}\z/D', $optionKey) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $quoteFingerprint) !== 1
                || strtoupper($currency) !== $currency
            ) {
                throw new OrderDataException();
            }

            /** @var array<string, mixed> $normalized */
            $normalized = [
                'optionKey' => $optionKey,
                'quoteFingerprint' => $quoteFingerprint,
                'label' => $label,
                'currency' => $currency,
                ...self::amounts($data, $currency),
                'deliveryEstimate' => self::deliveryEstimate($data['deliveryEstimate']),
                'taxBehavior' => OrderData::nullableSingleLine($data['taxBehavior'], 255),
                'taxCode' => self::taxCode($data['taxCode']),
            ];

            return new self($normalized);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function total(): string
    {
        return OrderData::text($this->data['total']);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{subtotal: string, providerSubtotal: int, tax: string, providerTax: int, total: string, providerTotal: int}
     */
    private static function amounts(array $data, string $currency): array
    {
        $amounts = [];
        $registry = new StripeCurrencyRegistry();
        // Retain Stripe's integer representation beside the decimal amount so
        // special provider currency units cannot be reinterpreted after storage.
        $fields = [
            'subtotal' => 'providerSubtotal',
            'tax' => 'providerTax',
            'total' => 'providerTotal',
        ];

        foreach ($fields as $amountField => $providerField) {
            $providerAmount = OrderData::integer($data[$providerField]);

            if ($providerAmount < 0) {
                throw new OrderDataException();
            }

            $amount = $registry->toMoney($registry->fromProviderAmount($providerAmount, $currency));

            if ((string) $amount->getAmount() !== OrderData::text($data[$amountField])) {
                throw new OrderDataException();
            }

            $amounts[$amountField] = (string) $amount->getAmount();
            $amounts[$providerField] = $providerAmount;
        }

        return $amounts;
    }

    /** @return array{minimum: ?int, maximum: ?int, unit: string}|null */
    private static function deliveryEstimate(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $estimate = OrderData::map($value);
        OrderData::validateAllowedKeys($estimate, ['minimum', 'maximum', 'unit']);
        OrderData::validateRequiredKeys($estimate, ['minimum', 'maximum', 'unit']);
        $minimum = $estimate['minimum'] === null ? null : OrderData::integer($estimate['minimum']);
        $maximum = $estimate['maximum'] === null ? null : OrderData::integer($estimate['maximum']);
        $unit = DeliveryEstimateUnit::from(OrderData::text($estimate['unit']));

        return (new DeliveryEstimate($minimum, $maximum, $unit))->toArray();
    }

    private static function taxCode(mixed $value): ?string
    {
        $taxCode = OrderData::nullableSingleLine($value, 255);

        if ($taxCode !== null && preg_match('/\Atxcd_[A-Za-z0-9]{1,249}\z/D', $taxCode) !== 1) {
            throw new OrderDataException();
        }

        return $taxCode;
    }
}
