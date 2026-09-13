<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Tax;

use Stripe\StripeClient;

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
            // Requirement metadata is returned by the pinned API even though
            // the installed SDK does not yet document it on TaxCode.
            // https://docs.stripe.com/api/tax_codes/object#tax_code_object-requirements-performance_location
            $requirements = $taxCode->toArray()['requirements'] ?? null;
            $taxCodes[] = new TaxCodeRecord(
                id: $taxCode->id,
                name: $taxCode->name,
                description: $taxCode->description,
                requiresPerformanceLocation: is_array($requirements) && ($requirements['performance_location'] ?? null) === 'required',
            );
        }

        return new TaxCodeListResult($taxCodes, $collection->has_more);
    }
}
