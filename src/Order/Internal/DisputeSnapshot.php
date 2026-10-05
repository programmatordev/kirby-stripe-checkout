<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use Stripe\Dispute;
use Throwable;

/** @internal One dispute/inquiry's safe current facts and committed local observation history. */
final readonly class DisputeSnapshot
{
    private const KEYS = ['stripeDisputeId', 'stripePaymentIntentId', 'stripeChargeId', 'currency', 'amount', 'status', 'reason', 'evidenceDueBy', 'evidenceHasEvidence', 'evidencePastDue', 'evidenceSubmissionCount', 'balanceTransactions', 'createdAt', 'firstObservedAt', 'updatedAt'];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data) {}

    /** @param array<string, mixed> $data Selected untrusted Stripe facts. */
    public static function fromStripe(array $data, string $paymentIntentId): self
    {
        try {
            if (($data['object'] ?? null) !== Dispute::OBJECT_NAME) {
                throw new OrderDataException();
            }

            $currency = strtoupper(OrderData::string($data['currency'] ?? null));
            $registry = new StripeCurrencyRegistry();
            $amount = $registry->toMoney($registry->fromProviderAmount(OrderData::integer($data['amount'] ?? null), $currency));

            $evidenceDetails = OrderData::map($data['evidence_details'] ?? null);

            return self::validate([
                'stripeDisputeId' => $data['id'] ?? null,
                'stripePaymentIntentId' => $paymentIntentId,
                'stripeChargeId' => $data['charge'] ?? null,
                'currency' => $currency,
                'amount' => (string) $amount->getAmount(),
                'status' => $data['status'] ?? null,
                'reason' => $data['reason'] ?? null,
                'evidenceDueBy' => $evidenceDetails['due_by'] ?? null,
                'evidenceHasEvidence' => $evidenceDetails['has_evidence'] ?? null,
                'evidencePastDue' => $evidenceDetails['past_due'] ?? null,
                'evidenceSubmissionCount' => $evidenceDetails['submission_count'] ?? null,
                'balanceTransactions' => $data['balance_transactions'] ?? null,
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
        $references = ['stripeDisputeId', 'stripePaymentIntentId', 'stripeChargeId'];

        foreach ($references as $field) {
            OrderData::nonEmptyString($data[$field]);
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
        $statuses = [Dispute::STATUS_NEEDS_RESPONSE, Dispute::STATUS_UNDER_REVIEW, Dispute::STATUS_WON, Dispute::STATUS_LOST, Dispute::STATUS_PREVENTED, Dispute::STATUS_WARNING_NEEDS_RESPONSE, Dispute::STATUS_WARNING_UNDER_REVIEW, Dispute::STATUS_WARNING_CLOSED];

        if (in_array($data['status'], $statuses, true) === false) {
            throw new OrderDataException();
        }

        if (OrderData::integer($data['createdAt']) < 0) {
            throw new OrderDataException();
        }

        OrderData::string($data['reason']);
        OrderData::boolean($data['evidenceHasEvidence']);
        OrderData::boolean($data['evidencePastDue']);

        if ($data['evidenceDueBy'] !== null && OrderData::integer($data['evidenceDueBy']) < 0) {
            throw new OrderDataException();
        }

        if (OrderData::integer($data['evidenceSubmissionCount']) < 0) {
            throw new OrderDataException();
        }

        $balanceTransactions = [];

        foreach (OrderData::list($data['balanceTransactions']) as $item) {
            $balanceTransaction = $stored
                ? DisputeBalanceTransaction::fromArray(OrderData::map($item))
                : DisputeBalanceTransaction::fromStripe(OrderData::map($item));
            $id = $balanceTransaction->stripeBalanceTransactionId();

            if (isset($balanceTransactions[$id])) {
                throw new OrderDataException();
            }

            $balanceTransactions[$id] = $balanceTransaction->toArray();
        }

        // Provider ordering alone must not create a material change or another lifecycle delivery.
        ksort($balanceTransactions, SORT_STRING);
        $data['balanceTransactions'] = array_values($balanceTransactions);

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

    public function stripeDisputeId(): string
    {
        return OrderData::string($this->data['stripeDisputeId']);
    }

    public function stripePaymentIntentId(): string
    {
        return OrderData::string($this->data['stripePaymentIntentId']);
    }

    public function stripeChargeId(): string
    {
        return OrderData::string($this->data['stripeChargeId']);
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
