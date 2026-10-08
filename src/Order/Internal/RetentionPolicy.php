<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;
use ProgrammatorDev\StripeCheckout\Order\CheckoutStatus;
use ProgrammatorDev\StripeCheckout\Order\PaymentStatus;

/** @internal Terminal unpaid eligibility and automatic retention policy; never scans, reconciles or deletes an order. */
final readonly class RetentionPolicy
{
    public function __construct(private Settings $settings) {}

    /** @param array<string, mixed> $data Validated, freshly loaded canonical facts. */
    public function needsSessionDiscovery(array $data, DateTimeImmutable $checkedAt): bool
    {
        if ($this->settings->cleanupIncompleteOrders() === false || isset($data['stripeCheckoutSessionId'])) {
            return false;
        }

        if (in_array($data['checkoutStatus'], [CheckoutStatus::Creating->value, CheckoutStatus::CreationUncertain->value], true) === false) {
            return false;
        }

        // The retention threshold starts recovery, not deletion of an unresolved attempt.
        return $this->oldEnough($data['createdAt'], $this->settings->incompleteOrderRetentionDays(), $checkedAt);
    }

    /** @param array<string, mixed> $data Validated, freshly loaded canonical facts. */
    public function isEligible(array $data, DateTimeImmutable $now): bool
    {
        if (self::isTerminalUnpaid($data) === false) {
            return false;
        }

        if ($data['checkoutStatus'] !== CheckoutStatus::Complete->value) {
            // Terminal eligibility is established above; age alone cannot resolve an open or uncertain Checkout.
            // Recovery may observe expiry later, but it must not restart an incomplete order's retention clock.
            return $this->settings->cleanupIncompleteOrders()
                && $this->oldEnough($data['createdAt'], $this->settings->incompleteOrderRetentionDays(), $now);
        }

        if ($this->settings->cleanupUnpaidOrders() === false) {
            return false;
        }

        // A delayed payment can fail after Checkout completes.
        // Start retention only once both facts are terminal, not while payment was still pending.
        $terminalAt = max(OrderData::string($data['checkoutCompletedAt']), OrderData::string($data['paymentFailedAt']));

        return $this->oldEnough($terminalAt, $this->settings->unpaidOrderRetentionDays(), $now);
    }

    /** @param array<string, mixed> $data Validated, freshly loaded canonical facts. */
    public static function isTerminalUnpaid(array $data): bool
    {
        if (in_array($data['paymentStatus'], [PaymentStatus::Unpaid->value, PaymentStatus::Failed->value], true) === false) {
            return false;
        }

        // Manual deletion shares this safety rule, independently of automatic cleanup switches and age.
        return match ($data['checkoutStatus']) {
            CheckoutStatus::CreationFailed->value => isset($data['stripeCheckoutSessionId']) === false,
            CheckoutStatus::Expired->value => true,
            CheckoutStatus::Complete->value => $data['paymentStatus'] === PaymentStatus::Failed->value,
            default => false,
        };
    }

    private function oldEnough(mixed $timestamp, int $days, DateTimeImmutable $now): bool
    {
        // Compare whole elapsed UTC days; timezone/DST changes must not shorten a retention period, and large configured periods must not overflow.
        $age = $now->getTimestamp() - OrderData::date($timestamp)->getTimestamp();

        return $age >= 0 && intdiv($age, 86400) >= $days;
    }
}
