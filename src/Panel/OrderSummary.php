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
    /** @param list<string> $references Exact searchable local and Stripe references. */
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
        private bool $refundHasActive,
        private bool $refundRequiresAction,
        private bool $refundHasFailed,
        private bool $disputeRequiresResponse,
        private bool $disputeHasLost,
        private bool $failedEvents,
        private bool $failedHooks,
        private array $references,
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

    public function refundHasActive(): bool
    {
        return $this->refundHasActive;
    }

    public function refundRequiresAction(): bool
    {
        return $this->refundRequiresAction;
    }

    public function refundHasFailed(): bool
    {
        return $this->refundHasFailed;
    }

    public function disputeRequiresResponse(): bool
    {
        return $this->disputeRequiresResponse;
    }

    public function disputeHasLost(): bool
    {
        return $this->disputeHasLost;
    }

    public function failedEvents(): bool
    {
        return $this->failedEvents;
    }

    public function failedHooks(): bool
    {
        return $this->failedHooks;
    }

    public function matchesSearch(string $search): bool
    {
        if (in_array($search, $this->references, true)) {
            return true;
        }

        return $this->customerEmail !== null
            && mb_strtolower(trim($this->customerEmail)) === mb_strtolower($search);
    }
}
