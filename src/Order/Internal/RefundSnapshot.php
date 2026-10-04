<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Stripe\Refund;
use Throwable;

/** @internal One refund attempt's safe current facts and committed local observation history. */
final readonly class RefundSnapshot
{
    private const KEYS = ['stripeRefundId', 'stripePaymentIntentId', 'stripeChargeId', 'currency', 'amount', 'status', 'reason', 'failureReason', 'pendingReason', 'createdAt', 'firstObservedAt', 'updatedAt'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data Selected untrusted Stripe facts. */
    public static function fromStripe(array $data, string $paymentIntentId): self
    {
        try {
            if (($data['object'] ?? null) !== Refund::OBJECT_NAME) {
                throw new OrderDataException();
            }

            $currency = strtoupper(OrderData::string($data['currency'] ?? null));
            $registry = new StripeCurrencyRegistry();
            $amount = $registry->toMoney($registry->fromProviderAmount(OrderData::integer($data['amount'] ?? null), $currency));

            return self::validate([
                'stripeRefundId' => $data['id'] ?? null,
                'stripePaymentIntentId' => $paymentIntentId,
                'stripeChargeId' => $data['charge'] ?? null,
                'currency' => $currency,
                'amount' => (string) $amount->getAmount(),
                'status' => $data['status'] ?? null,
                'reason' => $data['reason'] ?? null,
                'failureReason' => $data['failure_reason'] ?? null,
                'pendingReason' => $data['pending_reason'] ?? null,
                'createdAt' => $data['created'] ?? null,
                'firstObservedAt' => null,
                'updatedAt' => null,
            ], stored: false);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        try {
            return self::validate($data, stored: true);
        } catch (Throwable) {
            throw new OrderDataException();
        }
    }

    /** @param array<string, mixed> $data */
    private static function validate(array $data, bool $stored): self
    {
        OrderData::validateAllowedKeys($data, self::KEYS);
        OrderData::validateRequiredKeys($data, self::KEYS);
        // Restoration requires identities, but their format belongs to Stripe.
        $references = ['stripeRefundId', 'stripePaymentIntentId', 'stripeChargeId'];

        foreach ($references as $field) {
            if ($field === 'stripeChargeId' && $data[$field] === null) {
                continue;
            }

            if (OrderData::string($data[$field]) === '') {
                throw new OrderDataException();
            }
        }

        $currency = OrderData::string($data['currency']);
        $registry = new StripeCurrencyRegistry();
        $amount = $registry->toMoney($registry->fromDecimal(OrderData::text($data['amount']), $currency));

        if ($currency !== strtoupper($currency)) {
            throw new OrderDataException();
        }

        if ($amount->isPositive() === false) {
            throw new OrderDataException();
        }

        $data['amount'] = (string) $amount->getAmount();
        $statuses = [Refund::STATUS_PENDING, Refund::STATUS_REQUIRES_ACTION, Refund::STATUS_SUCCEEDED, Refund::STATUS_FAILED, Refund::STATUS_CANCELED];

        if (in_array($data['status'], $statuses, true) === false) {
            throw new OrderDataException();
        }

        if (OrderData::integer($data['createdAt']) < 0) {
            throw new OrderDataException();
        }

        $reasonFields = ['reason', 'failureReason', 'pendingReason'];

        foreach ($reasonFields as $field) {
            // Provider reason codes stay opaque; the snapshot requires only nullable strings safe for serialization.
            OrderData::nullableString($data[$field]);
        }

        if ($stored) {
            if (OrderData::date($data['firstObservedAt']) > OrderData::date($data['updatedAt'])) {
                throw new OrderDataException();
            }
        }

        return new self(OrderData::map($data));
    }

    public function observed(?self $previous, DateTimeImmutable $now): self
    {
        // Observation history follows material fact changes, not every provider read.
        if ($previous !== null && $this->facts() === $previous->facts()) {
            return $previous;
        }

        // A backward local clock must not move a material update before the preceding observation.
        $timestamp = max(OrderData::timestamp($now), $previous?->data['updatedAt']);

        return new self([
            ...$this->data,
            'firstObservedAt' => $previous?->data['firstObservedAt'] ?? $timestamp,
            'updatedAt' => $timestamp,
        ]);
    }

    /** @return array<string, mixed> */
    private function facts(): array
    {
        $data = $this->data;
        unset($data['firstObservedAt'], $data['updatedAt']);

        return $data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function stripeRefundId(): string
    {
        return OrderData::string($this->data['stripeRefundId']);
    }

    public function stripePaymentIntentId(): string
    {
        return OrderData::string($this->data['stripePaymentIntentId']);
    }

    public function stripeChargeId(): ?string
    {
        return OrderData::nullableString($this->data['stripeChargeId']);
    }

    public function status(): string
    {
        return OrderData::string($this->data['status']);
    }

    public function amount(): Money
    {
        return Money::of(OrderData::string($this->data['amount']), OrderData::string($this->data['currency']));
    }
}
