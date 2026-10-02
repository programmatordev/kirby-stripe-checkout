<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use Brick\Money\Money;

/**
 * @internal Current provider payment facts, separate from canonical order state.
 * The reconciliation owner decides which monotonic state transition these facts justify.
 * An empty snapshot means no PaymentIntent was returned, not a failed payment;
 * its amount is absent rather than inferred from the Session total.
 * Method facts prefer the current PaymentIntent, falling back to its latest Charge
 * only when absent. Charge facts can describe a previous payment attempt.
 * This read value is not a persistence schema: nextAction() is transient provider data,
 * potentially including authentication directives, and must not enter stored order/hook snapshots.
 */
final readonly class PaymentSnapshot
{
    public function __construct(
        private ?Money $amount = null,
        private ?Money $amountReceived = null,
        private ?string $stripePaymentIntentId = null,
        private ?string $stripeChargeId = null,
        private ?string $stripePaymentMethodId = null,
        private ?string $paymentIntentStatus = null,
        private ?string $chargeStatus = null,
        private ?string $methodType = null,
        private ?int $createdAt = null,
        private ?int $chargeCreatedAt = null,
        private ?bool $chargePaid = null,
        private ?bool $chargeCaptured = null,
        private ?Money $amountCaptured = null,
        private ?string $failureCode = null,
        private ?PaymentAction $nextAction = null,
    ) {}

    public function amount(): ?Money
    {
        return $this->amount;
    }

    public function amountReceived(): ?Money
    {
        return $this->amountReceived;
    }

    public function stripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function stripeChargeId(): ?string
    {
        return $this->stripeChargeId;
    }

    public function stripePaymentMethodId(): ?string
    {
        return $this->stripePaymentMethodId;
    }

    public function paymentIntentStatus(): ?string
    {
        return $this->paymentIntentStatus;
    }

    public function chargeStatus(): ?string
    {
        return $this->chargeStatus;
    }

    public function methodType(): ?string
    {
        return $this->methodType;
    }

    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    public function chargeCreatedAt(): ?int
    {
        return $this->chargeCreatedAt;
    }

    public function chargePaid(): ?bool
    {
        return $this->chargePaid;
    }

    public function chargeCaptured(): ?bool
    {
        return $this->chargeCaptured;
    }

    public function amountCaptured(): ?Money
    {
        return $this->amountCaptured;
    }

    /** Last reported provider failure; its presence alone does not establish current payment state. */
    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    public function nextActionType(): ?string
    {
        return $this->nextAction?->type();
    }

    public function nextAction(): ?PaymentAction
    {
        return $this->nextAction;
    }
}
