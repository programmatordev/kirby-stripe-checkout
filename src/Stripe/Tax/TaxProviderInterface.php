<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

/** @internal Read-only boundary for Stripe classification and account readiness. */
interface TaxProviderInterface
{
    public function listTaxCodes(?string $startingAfter = null): TaxCodeListResult;

    public function retrieveSettings(): TaxSettingsRecord;
}
