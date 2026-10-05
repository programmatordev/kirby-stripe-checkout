<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use DateTimeImmutable;
use ProgrammatorDev\StripeCheckout\Configuration\Settings;

/** @internal Terminal unpaid eligibility and automatic retention policy; never scans, reconciles or deletes an order. */
final readonly class RetentionPolicy
{
    public function __construct(private Settings $settings) {}

    /** @param array<string, mixed> $data Validated, freshly loaded canonical facts. */
    public function isEligible(array $data, DateTimeImmutable $now): bool
    {
        if (self::isTerminalUnpaid($data) === false) {
            return false;
        }

        if ($data['checkoutStatus'] === 'creation_failed') {
            return $this->settings->cleanupCreationFailures()
                && $this->oldEnough($data['creationFailedAt'], $this->settings->creationFailureRetentionDays(), $now);
        }

        if ($this->settings->cleanupUnpaidOrders() === false) {
            return false;
        }

        // A delayed payment can fail after Checkout completes.
        // Start retention only once both facts are terminal, not while payment was still pending.
        $terminalAt = match (true) {
            $data['checkoutStatus'] === 'expired' => $data['checkoutExpiredAt'],
            $data['checkoutStatus'] === 'complete'
                => max(OrderData::string($data['checkoutCompletedAt']), OrderData::string($data['paymentFailedAt'])),
            default => null,
        };

        return $terminalAt !== null && $this->oldEnough($terminalAt, $this->settings->unpaidOrderRetentionDays(), $now);
    }

    /** @param array<string, mixed> $data Validated, freshly loaded canonical facts. */
    public static function isTerminalUnpaid(array $data): bool
    {
        if (in_array($data['paymentStatus'], ['unpaid', 'failed'], true) === false) {
            return false;
        }

        // Manual deletion shares this safety rule, independently of automatic cleanup switches and age.
        return match ($data['checkoutStatus']) {
            'creation_failed' => isset($data['stripeCheckoutSessionId']) === false,
            'expired' => true,
            'complete' => $data['paymentStatus'] === 'failed',
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
