<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe;

/** @internal Decides when a last-good catalogue may refresh, without expiring it. */
final class CatalogueRefreshPolicy
{
    /**
     * Applies only to automatic loads; explicit refresh bypasses this policy.
     * Failure cooldown also applies when the first fetch produced no snapshot.
     */
    public static function shouldRefresh(
        ?int $refreshedAt,
        ?int $failedAt,
        int $refreshAfterSeconds,
        int $failureCooldownSeconds,
    ): bool {
        $now = time();
        $refreshDue = $refreshedAt === null || $refreshedAt <= $now - $refreshAfterSeconds;
        $retryAllowed = $failedAt === null || $failedAt <= $now - $failureCooldownSeconds;

        return $refreshDue && $retryAllowed;
    }
}
