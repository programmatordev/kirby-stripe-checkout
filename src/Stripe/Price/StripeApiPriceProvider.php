<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Stripe\Price;

use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;
use Stripe\TaxCode;

/**
 * Adapts stripe-php Price/Product responses to the internal read boundary.
 *
 * @internal
 */
final class StripeApiPriceProvider implements PriceProviderInterface
{
    public function __construct(private readonly StripeClient $client) {}

    public function list(string $currency, ?string $startingAfter = null): PriceListResult
    {
        $params = [
            'active' => true,
            'currency' => strtolower($currency),
            'expand' => ['data.product'],
            'limit' => 100,
            'type' => Price::TYPE_ONE_TIME,
        ];

        if ($startingAfter !== null) {
            $params['starting_after'] = $startingAfter;
        }

        $collection = $this->client->prices->all($params);
        $prices = [];

        foreach ($collection->data as $price) {
            $prices[] = $this->record($price);
        }

        return new PriceListResult($prices, $collection->has_more);
    }

    public function retrieve(string $priceId): PriceRecord
    {
        $price = $this->client->prices->retrieve($priceId, [
            'expand' => ['product'],
        ]);

        return $this->record($price);
    }

    private function record(Price $price): PriceRecord
    {
        $product = $price->product;

        if (is_string($product)) {
            $product = $this->client->products->retrieve($product);
        }

        return new PriceRecord(
            priceId: $price->id,
            active: $price->active,
            billingScheme: $price->billing_scheme,
            currency: $price->currency,
            hasCustomUnitAmount: $price->custom_unit_amount !== null,
            nickname: $price->nickname,
            hasRecurring: $price->recurring !== null,
            taxBehavior: $price->tax_behavior,
            hasTiers: isset($price->tiers),
            tiersMode: $price->tiers_mode,
            hasQuantityTransform: $price->transform_quantity !== null,
            type: $price->type,
            unitAmount: $price->unit_amount,
            unitAmountDecimal: $price->unit_amount_decimal,
            productId: $product->id,
            productActive: $product->active,
            productName: $product->name,
            productDescription: $product->description,
            productImages: $this->images($product),
            productTaxCode: $this->taxCode($product),
        );
    }

    /** @return list<string> */
    private function images(Product $product): array
    {
        return array_values($product->images);
    }

    private function taxCode(Product $product): ?string
    {
        if ($product->tax_code instanceof TaxCode) {
            return $product->tax_code->id;
        }

        return $product->tax_code;
    }
}
