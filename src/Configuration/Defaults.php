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
    public const CHECKOUT_EXPIRATION_MINUTES_MIN = 30;
    public const CHECKOUT_EXPIRATION_MINUTES_MAX = 1440;
    public const CHECKOUT_EXPIRATION_MINUTES_DEFAULT = 1440;

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
        'checkoutExpirationMinutes' => self::CHECKOUT_EXPIRATION_MINUTES_DEFAULT,
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
