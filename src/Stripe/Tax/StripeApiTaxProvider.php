<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use Stripe\StripeClient;
use Stripe\StripeObject;
use UnexpectedValueException;

/** @internal Projects stripe-php responses onto the read-only tax boundary. */
final class StripeApiTaxProvider implements TaxProviderInterface
{
    public function __construct(private readonly StripeClient $client) {}

    public function listTaxCodes(?string $startingAfter = null): TaxCodeListResult
    {
        $parameters = ['limit' => 100];

        if ($startingAfter !== null) {
            $parameters['starting_after'] = $startingAfter;
        }

        $collection = $this->client->taxCodes->all($parameters);
        $taxCodes = [];

        foreach ($collection->data as $taxCode) {
            $taxCodes[] = new TaxCodeRecord(
                id: $taxCode->id,
                name: $taxCode->name,
                description: $taxCode->description,
            );
        }

        return new TaxCodeListResult($taxCodes, $collection->has_more);
    }

    public function retrieveSettings(): TaxSettingsRecord
    {
        $settings = $this->client->tax->settings->retrieve();
        /** @var StripeObject|null $pending */
        $pending = $settings->status_details->pending ?? null;
        // Active settings may omit pending details; normalize absent or null
        // missing_fields to an empty list, not an account-read failure.
        $pendingData = $pending?->toArray() ?? [];
        $missingFields = $pendingData['missing_fields'] ?? [];

        if (is_array($missingFields) === false) {
            throw new UnexpectedValueException('Invalid Stripe Tax Settings missing fields.');
        }

        return new TaxSettingsRecord(
            status: $settings->status,
            liveMode: $settings->livemode,
            defaultTaxCode: $settings->defaults->tax_code,
            defaultTaxBehavior: $settings->defaults->tax_behavior,
            missingFields: $missingFields,
        );
    }
}
