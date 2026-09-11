<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Checkout\UiMode;

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
        'uiMode' => UiMode::Hosted->value,
        'successDestination' => null,
        'cancelDestination' => null,
        'returnDestination' => null,
        ...self::RETENTION,
    ];

    public const HOUSEKEEPING = [
        'intervalHours' => 24,
        'batchSize' => 25,
    ];
}
