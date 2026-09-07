<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

/**
 * Shared business defaults for runtime resolution and native Settings fields.
 *
 * @internal
 */
final class Defaults
{
    public const RETENTION = [
        'cleanupCreationFailures' => true,
        'creationFailureRetentionDays' => 7,
        'cleanupUnpaidOrders' => true,
        'unpaidOrderRetentionDays' => 30,
    ];

    public const SETTINGS = [
        'priceSource' => 'kirby',
        'currency' => null,
        'defaultRequiresShipping' => null,
        ...self::RETENTION,
    ];

    public const HOUSEKEEPING = [
        'intervalHours' => 24,
        'batchSize' => 25,
    ];
}
