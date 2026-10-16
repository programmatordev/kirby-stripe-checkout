<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout;

use InvalidArgumentException;
use Kirby\Cms\Page;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;

/** One short-lived same-browser Checkout navigation result. */
final readonly class CheckoutResult
{
    /** @internal Resolved by the Site API from native session state. */
    public function __construct(
        private CheckoutResultReason $reason,
        private ?Page $order,
        private ?CheckoutStatus $checkoutStatus,
        private ?PaymentStatus $paymentStatus,
        private bool $canResumeCheckout,
    ) {
        $available = $order !== null;

        if (
            $available !== ($checkoutStatus !== null)
            || $available !== ($paymentStatus !== null)
            || $canResumeCheckout && ($available === false || $reason !== CheckoutResultReason::EmbeddedReturn)
        ) {
            throw new InvalidArgumentException('The Checkout result is inconsistent.');
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

    public function canResumeCheckout(): bool
    {
        return $this->canResumeCheckout;
    }
}
