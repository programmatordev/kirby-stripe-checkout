<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal One bounded, fixed-vocabulary Orders index query. */
final readonly class OrderListQuery
{
    private const INVALID_QUERY_MESSAGE = 'Invalid Order list query.';

    private ?string $search;

    public function __construct(
        private int $page = 1,
        ?string $search = null,
        private ?CheckoutStatus $checkoutStatus = CheckoutStatus::Complete,
        private ?PaymentStatus $paymentStatus = null,
        private ?RefundStatus $refundStatus = null,
        private ?OrderDisputeFilter $disputeFilter = null,
        private ?OrderAttentionFilter $attentionFilter = null,
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException(self::INVALID_QUERY_MESSAGE);
        }

        if ($this->paymentStatus !== null && in_array($this->paymentStatus, [
            PaymentStatus::Pending,
            PaymentStatus::Paid,
            PaymentStatus::Failed,
            PaymentStatus::NoPaymentRequired,
        ], true) === false) {
            throw new InvalidArgumentException(self::INVALID_QUERY_MESSAGE);
        }

        if ($this->refundStatus !== null && in_array($this->refundStatus, [
            RefundStatus::Partial,
            RefundStatus::Full,
        ], true) === false) {
            throw new InvalidArgumentException(self::INVALID_QUERY_MESSAGE);
        }

        $search = $search === null ? null : trim($search);

        try {
            $this->search = $search === null || $search === ''
                ? null
                : OrderData::text($search);
        } catch (OrderDataException $error) {
            throw new InvalidArgumentException(self::INVALID_QUERY_MESSAGE, previous: $error);
        }
    }

    public function page(): int
    {
        return $this->page;
    }

    public function search(): ?string
    {
        return $this->search;
    }

    public function checkoutStatus(): ?CheckoutStatus
    {
        return $this->checkoutStatus;
    }

    public function paymentStatus(): ?PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function refundStatus(): ?RefundStatus
    {
        return $this->refundStatus;
    }

    public function disputeFilter(): ?OrderDisputeFilter
    {
        return $this->disputeFilter;
    }

    public function attentionFilter(): ?OrderAttentionFilter
    {
        return $this->attentionFilter;
    }
}
