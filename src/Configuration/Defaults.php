<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Configuration;

use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Collection\BillingAddressCollection;
use ProgrammatorDev\StripeCheckout\Collection\CollectionMode;
use ProgrammatorDev\StripeCheckout\Collection\TaxIdCollection;

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
        'billingAddressCollection' => BillingAddressCollection::Auto->value,
        'individualNameCollection' => CollectionMode::Optional->value,
        'businessNameCollection' => CollectionMode::Off->value,
        'phoneNumberCollection' => false,
        'taxIdCollection' => TaxIdCollection::Off->value,
        'termsOfServiceConsent' => false,
        'promotionsConsent' => false,
        'customFields' => [],
        'allowPromotionCodes' => false,
        ...self::RETENTION,
    ];

    public const HOUSEKEEPING = [
        'intervalHours' => 24,
        'batchSize' => 25,
    ];
}
