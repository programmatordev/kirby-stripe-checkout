<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use InvalidArgumentException;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal One bounded, fixed-vocabulary Orders index query. */
final readonly class OrderListQuery
{
    private const INVALID_QUERY_MESSAGE = 'Invalid Order list query.';

    /** @var list<CheckoutStatus> */
    private array $checkoutStatuses;

    /** @var list<PaymentStatus> */
    private array $paymentStatuses;

    /** @var list<RefundStatus> */
    private array $refundStatuses;

    /** @var list<DisputeStatus> */
    private array $disputeStatuses;

    /** @var list<OrderAttentionFilter> */
    private array $attentionFilters;

    private ?string $search;

    /**
     * @param list<CheckoutStatus>|null $checkoutStatuses
     * @param list<PaymentStatus>|null $paymentStatuses
     * @param list<RefundStatus> $refundStatuses
     * @param list<DisputeStatus> $disputeStatuses
     * @param list<OrderAttentionFilter> $attentionFilters
     */
    public function __construct(
        private int $page = 1,
        ?string $search = null,
        ?array $checkoutStatuses = null,
        ?array $paymentStatuses = null,
        array $refundStatuses = [],
        array $disputeStatuses = [],
        array $attentionFilters = [],
    ) {
        if ($this->page < 1) {
            throw new InvalidArgumentException(self::INVALID_QUERY_MESSAGE);
        }

        $this->checkoutStatuses = $checkoutStatuses ?? [CheckoutStatus::Complete];
        $this->paymentStatuses = $paymentStatuses ?? [
            PaymentStatus::Paid,
            PaymentStatus::NoPaymentRequired,
        ];
        $this->refundStatuses = $refundStatuses;
        $this->disputeStatuses = $disputeStatuses;
        $this->attentionFilters = $attentionFilters;

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

    /** @return list<CheckoutStatus> */
    public function checkoutStatuses(): array
    {
        return $this->checkoutStatuses;
    }

    /** @return list<PaymentStatus> */
    public function paymentStatuses(): array
    {
        return $this->paymentStatuses;
    }

    /** @return list<RefundStatus> */
    public function refundStatuses(): array
    {
        return $this->refundStatuses;
    }

    /** @return list<DisputeStatus> */
    public function disputeStatuses(): array
    {
        return $this->disputeStatuses;
    }

    /** @return list<OrderAttentionFilter> */
    public function attentionFilters(): array
    {
        return $this->attentionFilters;
    }
}
