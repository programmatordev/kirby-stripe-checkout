<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

/**
 * Narrows Stripe reads to the two operations needed by Price products.
 *
 * @internal
 */
interface PriceProviderInterface
{
    public function list(string $currency, ?string $startingAfter = null): PriceListResult;

    public function retrieve(string $priceId): PriceRecord;
}
