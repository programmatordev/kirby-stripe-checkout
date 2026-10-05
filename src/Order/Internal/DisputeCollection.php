<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Stripe\Dispute;

/** @internal Complete current disputes/inquiries and their independent operational summary. */
final readonly class DisputeCollection
{
    /** @param list<DisputeSnapshot> $disputes */
    private function __construct(private array $disputes) {}

    /** @param list<DisputeSnapshot> $disputes */
    public static function fromSnapshots(array $disputes, string $paymentIntentId, Money $paymentAmount): self
    {
        $ids = [];

        foreach ($disputes as $dispute) {
            if (isset($ids[$dispute->stripeDisputeId()])) {
                throw new OrderDataException();
            }

            if ($dispute->stripePaymentIntentId() !== $paymentIntentId) {
                throw new OrderDataException();
            }

            // Correlate currency only: disputed money can exceed the original charge and is not a refunded-money total.
            // https://docs.stripe.com/disputes/how-disputes-work#disputed-amount
            if ($dispute->amount()->getCurrency() != $paymentAmount->getCurrency()) {
                throw new OrderDataException();
            }

            $ids[$dispute->stripeDisputeId()] = true;
        }

        // Provider ordering alone must not change the canonical collection or emit another notification.
        usort($disputes, static fn(DisputeSnapshot $a, DisputeSnapshot $b): int => strcmp($a->stripeDisputeId(), $b->stripeDisputeId()));

        return new self($disputes);
    }

    /** @param list<mixed> $data */
    public static function fromArray(array $data, string $paymentIntentId, Money $paymentAmount): self
    {
        return self::fromSnapshots(
            disputes: array_map(static fn(mixed $item): DisputeSnapshot => DisputeSnapshot::fromArray(OrderData::map($item)), $data),
            paymentIntentId: $paymentIntentId,
            paymentAmount: $paymentAmount,
        );
    }

    public function observed(?self $previous, DateTimeImmutable $now): self
    {
        $previousDisputes = [];

        foreach ($previous->disputes ?? [] as $dispute) {
            $previousDisputes[$dispute->stripeDisputeId()] = $dispute;
        }

        $disputes = [];

        foreach ($this->disputes as $dispute) {
            $disputes[] = $dispute->observed($previousDisputes[$dispute->stripeDisputeId()] ?? null, $now);
        }

        return new self($disputes);
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn(DisputeSnapshot $dispute): array => $dispute->toArray(), $this->disputes);
    }

    public function disputeStatus(): DisputeStatus
    {
        if ($this->disputes === []) {
            return DisputeStatus::None;
        }

        // Active cases take precedence; the independent lost flag still exposes terminal losses.
        if ($this->disputeRequiresResponse()) {
            return DisputeStatus::NeedsResponse;
        }

        if ($this->hasStatus(Dispute::STATUS_UNDER_REVIEW) || $this->hasStatus(Dispute::STATUS_WARNING_UNDER_REVIEW)) {
            return DisputeStatus::UnderReview;
        }

        if ($this->disputeHasLost() === false) {
            return DisputeStatus::ResolvedFavorable;
        }

        foreach ($this->disputes as $dispute) {
            if ($dispute->status() !== Dispute::STATUS_LOST) {
                return DisputeStatus::Mixed;
            }
        }

        return DisputeStatus::ResolvedLost;
    }

    public function disputeRequiresResponse(): bool
    {
        return $this->hasStatus(Dispute::STATUS_NEEDS_RESPONSE) || $this->hasStatus(Dispute::STATUS_WARNING_NEEDS_RESPONSE);
    }

    public function disputeHasLost(): bool
    {
        return $this->hasStatus(Dispute::STATUS_LOST);
    }

    private function hasStatus(string $status): bool
    {
        foreach ($this->disputes as $dispute) {
            if ($dispute->status() === $status) {
                return true;
            }
        }

        return false;
    }
}
