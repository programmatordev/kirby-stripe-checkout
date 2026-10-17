<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use InvalidArgumentException;
use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\Payment;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;

/** One short-lived same-browser Checkout navigation result. */
final readonly class CheckoutResult
{
    private const INCONSISTENT_MESSAGE = 'The Checkout result is inconsistent.';

    /** @internal Resolved by the Site API from native session state. */
    public function __construct(
        private CheckoutResultReason $reason,
        private ?Page $order,
        private ?CheckoutStatus $checkoutStatus,
        private ?PaymentStatus $paymentStatus,
        private ?Payment $payment,
        private bool $canResumeCheckout,
    ) {
        $available = $order !== null;

        if ($available !== ($checkoutStatus !== null)) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if ($available !== ($paymentStatus !== null)) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if ($available === false && $payment !== null) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if ($payment !== null && $payment->status() !== $paymentStatus) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if (
            $payment?->nextAction() !== null
            && (
                $reason === CheckoutResultReason::HostedCancel
                || in_array($paymentStatus, [PaymentStatus::Unpaid, PaymentStatus::Pending], true) === false
            )
        ) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if ($canResumeCheckout && $available === false) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }

        if ($canResumeCheckout && $reason !== CheckoutResultReason::EmbeddedReturn) {
            throw new InvalidArgumentException(self::INCONSISTENT_MESSAGE);
        }
    }

    public function reason(): CheckoutResultReason
    {
        return $this->reason;
    }

    public function isAvailable(): bool
    {
        return $this->order !== null;
    }

    public function order(): ?Page
    {
        return $this->order;
    }

    public function checkoutStatus(): ?CheckoutStatus
    {
        return $this->checkoutStatus;
    }

    public function paymentStatus(): ?PaymentStatus
    {
        return $this->paymentStatus;
    }

    /** Current canonical facts with an optional action observed by this browser return. */
    public function payment(): ?Payment
    {
        return $this->payment;
    }

    public function canResumeCheckout(): bool
    {
        return $this->canResumeCheckout;
    }
}
