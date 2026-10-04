<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;
use Stripe\Refund;

/** @internal Complete deterministic refund attempts and their independent financial summary. */
final readonly class RefundCollection
{
    /** @param list<RefundSnapshot> $refunds */
    private function __construct(private array $refunds, private Money $paymentAmount) {}

    /** @param list<RefundSnapshot> $refunds */
    public static function fromSnapshots(array $refunds, string $paymentIntentId, Money $paymentAmount): self
    {
        $ids = [];

        foreach ($refunds as $refund) {
            if (isset($ids[$refund->stripeRefundId()])) {
                throw new OrderDataException();
            }

            if ($refund->stripePaymentIntentId() !== $paymentIntentId) {
                throw new OrderDataException();
            }

            if ($refund->amount()->getCurrency() != $paymentAmount->getCurrency()) {
                throw new OrderDataException();
            }

            $ids[$refund->stripeRefundId()] = true;
        }

        // Provider list ordering alone must not change the snapshot or emit another refund notification.
        usort($refunds, static fn(RefundSnapshot $a, RefundSnapshot $b): int => strcmp($a->stripeRefundId(), $b->stripeRefundId()));
        $collection = new self($refunds, $paymentAmount);

        if ($collection->refundedTotal()->isGreaterThan($paymentAmount)) {
            throw new OrderDataException();
        }

        return $collection;
    }

    /** @param list<mixed> $data */
    public static function fromArray(array $data, string $paymentIntentId, Money $paymentAmount): self
    {
        return self::fromSnapshots(
            refunds: array_map(static fn(mixed $item): RefundSnapshot => RefundSnapshot::fromArray(OrderData::map($item)), $data),
            paymentIntentId: $paymentIntentId,
            paymentAmount: $paymentAmount,
        );
    }

    public function observed(?self $previous, DateTimeImmutable $now): self
    {
        $previousRefunds = [];

        foreach ($previous->refunds ?? [] as $refund) {
            $previousRefunds[$refund->stripeRefundId()] = $refund;
        }

        $refunds = [];

        foreach ($this->refunds as $refund) {
            $refunds[] = $refund->observed($previousRefunds[$refund->stripeRefundId()] ?? null, $now);
        }

        return new self($refunds, $this->paymentAmount);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(RefundSnapshot $refund): array => $refund->toArray(), $this->refunds);
    }

    public function refundedTotal(): Money
    {
        $total = Money::zero($this->paymentAmount->getCurrency());

        foreach ($this->refunds as $refund) {
            if ($refund->status() === Refund::STATUS_SUCCEEDED) {
                $total = $total->plus($refund->amount());
            }
        }

        return $total;
    }

    public function refundStatus(): RefundStatus
    {
        if ($this->refunds === []) {
            return RefundStatus::None;
        }

        $total = $this->refundedTotal();

        // Reaching the full successful amount takes precedence; independent flags still expose other attempts.
        if ($total->isEqualTo($this->paymentAmount)) {
            return RefundStatus::Full;
        }

        if ($this->refundHasActive()) {
            return RefundStatus::Pending;
        }

        // Canceled-only attempts share the unsuccessful summary without becoming failed-record flags.
        return $total->isPositive() ? RefundStatus::Partial : RefundStatus::Failed;
    }

    public function refundHasActive(): bool
    {
        return $this->hasStatus(Refund::STATUS_PENDING) || $this->refundRequiresAction();
    }

    public function refundRequiresAction(): bool
    {
        return $this->hasStatus(Refund::STATUS_REQUIRES_ACTION);
    }

    public function refundHasFailed(): bool
    {
        return $this->hasStatus(Refund::STATUS_FAILED);
    }

    private function hasStatus(string $status): bool
    {
        foreach ($this->refunds as $refund) {
            if ($refund->status() === $status) {
                return true;
            }
        }

        return false;
    }
}
