<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Math\BigDecimal;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Throwable;

/** @internal Returned tax calculation and safe rate allocations, independent of payment state. */
final readonly class TaxSnapshot
{
    private const KEYS = ['automaticTaxEnabled', 'calculationStatus', 'provider', 'currency', 'amount', 'providerAmount', 'breakdown'];

    private const BREAKDOWN_KEYS = [
        'target', 'targetId', 'amount', 'providerAmount', 'currency',
        'taxableAmount', 'providerTaxableAmount', 'rateId', 'inclusive',
        'percentage', 'effectivePercentage', 'jurisdiction', 'jurisdictionLevel',
        'country', 'state', 'taxType', 'rateType', 'displayName', 'taxabilityReason',
    ];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            OrderData::validateAllowedKeys($data, self::KEYS);
            OrderData::validateRequiredKeys($data, self::KEYS);
            OrderData::boolean($data['automaticTaxEnabled']);
            OrderData::nullableSingleLine($data['calculationStatus'], 255);
            OrderData::nullableSingleLine($data['provider'], 255);
            $currency = OrderData::nullableString($data['currency']);
            self::validateAmount($data['amount'], $data['providerAmount'], $currency);

            if ($data['breakdown'] !== null) {
                $breakdown = [];
                $orderAllocated = 0;
                // An explicitly empty breakdown asserts no allocations. A list
                // containing only line/shipping entries does not cover the order total.
                $hasOrderAllocations = $data['breakdown'] === [];

                foreach (OrderData::list($data['breakdown']) as $entry) {
                    $entry = OrderData::map($entry);
                    OrderData::validateAllowedKeys($entry, self::BREAKDOWN_KEYS);
                    OrderData::validateRequiredKeys($entry, self::BREAKDOWN_KEYS);

                    if (in_array($entry['target'], ['order', 'line_item', 'shipping'], true) === false || $entry['currency'] !== $currency) {
                        throw new OrderDataException();
                    }

                    OrderData::nullableSingleLine($entry['targetId'], 255);
                    self::validateAmount($entry['amount'], $entry['providerAmount'], $currency);
                    self::validateAmount($entry['taxableAmount'], $entry['providerTaxableAmount'], $currency);

                    if ($entry['amount'] === null) {
                        throw new OrderDataException();
                    }

                    if ($entry['target'] === 'order') {
                        $hasOrderAllocations = true;
                        $providerAmount = OrderData::integer($entry['providerAmount']);

                        if ($providerAmount > PHP_INT_MAX - $orderAllocated) {
                            throw new OrderDataException();
                        }

                        $orderAllocated += $providerAmount;
                    }

                    if ($entry['inclusive'] !== null) {
                        OrderData::boolean($entry['inclusive']);
                    }

                    $textFields = ['rateId', 'jurisdiction', 'jurisdictionLevel', 'country', 'state', 'taxType', 'rateType', 'displayName', 'taxabilityReason'];

                    foreach ($textFields as $field) {
                        OrderData::nullableSingleLine($entry[$field], 255);
                    }

                    $percentages = ['percentage', 'effectivePercentage'];

                    foreach ($percentages as $field) {
                        if ($entry[$field] !== null) {
                            $percentage = BigDecimal::of(OrderData::text($entry[$field]));

                            if ($percentage->isLessThan(0)) {
                                throw new OrderDataException();
                            }

                            $entry[$field] = (string) $percentage->strippedOfTrailingZeros();
                        }
                    }

                    $breakdown[] = $entry;
                }

                $data['breakdown'] = $breakdown;

                // Check aggregate allocations only. Line/shipping targets overlap
                // them and must not be counted again or used to calculate tax.
                if ($hasOrderAllocations && $data['providerAmount'] !== null && $orderAllocated !== $data['providerAmount']) {
                    throw new OrderDataException();
                }
            }

            return new self(OrderData::map($data));
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function amount(): ?string
    {
        return OrderData::nullableString($this->data['amount']);
    }

    private static function validateAmount(mixed $amount, mixed $providerAmount, ?string $currency): void
    {
        if (($amount === null) !== ($providerAmount === null)) {
            throw new OrderDataException();
        }

        if ($currency !== null) {
            (new StripeCurrencyRegistry())->fromProviderAmount(0, $currency);

            if (strtoupper($currency) !== $currency) {
                throw new OrderDataException();
            }
        }

        if ($amount === null) {
            return;
        }

        $providerAmount = OrderData::integer($providerAmount);

        if ($currency === null || $providerAmount < 0) {
            throw new OrderDataException();
        }

        $registry = new StripeCurrencyRegistry();
        $money = $registry->toMoney($registry->fromProviderAmount($providerAmount, $currency));

        if ((string) $money->getAmount() !== OrderData::text($amount)) {
            throw new OrderDataException();
        }
    }
}
