<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Panel;

use Brick\Money\Money;
use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Kirby\OrderPage;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\DisputeStatus;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;
use ProgrammatorDev\StripeCheckout\Order\RefundStatus;

/** @internal Validated facts needed by one Orders index row. */
final readonly class OrderSummary
{
    public function __construct(
        private OrderPage $orderPage,
        private string $pageUuid,
        private string $orderNumber,
        private DateTimeImmutable $createdAt,
        private ?string $customerName,
        private ?string $customerEmail,
        private CheckoutStatus $checkoutStatus,
        private PaymentStatus $paymentStatus,
        private RefundStatus $refundStatus,
        private DisputeStatus $disputeStatus,
        private ?Money $total,
        private bool $failedEvents,
        private bool $failedHooks,
    ) {}

    public function orderPage(): OrderPage
    {
        return $this->orderPage;
    }

    public function pageUuid(): string
    {
        return $this->pageUuid;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function customerName(): ?string
    {
        return $this->customerName;
    }

    public function customerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function checkoutStatus(): CheckoutStatus
    {
        return $this->checkoutStatus;
    }

    public function paymentStatus(): PaymentStatus
    {
        return $this->paymentStatus;
    }

    public function refundStatus(): RefundStatus
    {
        return $this->refundStatus;
    }

    public function disputeStatus(): DisputeStatus
    {
        return $this->disputeStatus;
    }

    public function total(): ?Money
    {
        return $this->total;
    }

    public function failedEvents(): bool
    {
        return $this->failedEvents;
    }

    public function failedHooks(): bool
    {
        return $this->failedHooks;
    }
}
