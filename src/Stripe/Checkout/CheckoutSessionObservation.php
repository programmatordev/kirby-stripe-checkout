<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Checkout;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionAssociation;
use ProgrammatorDev\StripeCheckout\Order\Internal\CheckoutSessionSnapshot;
use ProgrammatorDev\StripeCheckout\Order\Internal\PaymentSnapshot;

/** @internal Complete correlated provider read; not a persisted or reconciled Order. */
final readonly class CheckoutSessionObservation
{
    /** @param list<CheckoutLineItemSnapshot> $lineItems */
    public function __construct(
        private CheckoutSessionAssociation $association,
        private string $status,
        private string $paymentStatus,
        private bool $liveMode,
        private int $createdAt,
        private int $expiresAt,
        private ?string $requestId,
        private ?string $stripeInvoiceId,
        private Money $subtotal,
        private Money $total,
        private array $lineItems,
        private PaymentSnapshot $payment,
        private CheckoutSessionSnapshot $snapshot,
    ) {}

    public function association(): CheckoutSessionAssociation
    {
        return $this->association;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function paymentStatus(): string
    {
        return $this->paymentStatus;
    }

    public function liveMode(): bool
    {
        return $this->liveMode;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function stripeInvoiceId(): ?string
    {
        return $this->stripeInvoiceId;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function total(): Money
    {
        return $this->total;
    }

    /** @return list<CheckoutLineItemSnapshot> */
    public function lineItems(): array
    {
        return $this->lineItems;
    }

    public function payment(): PaymentSnapshot
    {
        return $this->payment;
    }

    /** Canonical order fields, excluding the transient payment action returned by payment(). */
    public function snapshot(): CheckoutSessionSnapshot
    {
        return $this->snapshot;
    }
}
