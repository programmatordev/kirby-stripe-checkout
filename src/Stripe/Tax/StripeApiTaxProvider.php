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
            $taxCodes[] = new TaxCodeRecord(
                id: $taxCode->id,
                name: $taxCode->name,
                description: $taxCode->description,
            );
        }

        return new TaxCodeListResult($taxCodes, $collection->has_more);
    }
}
