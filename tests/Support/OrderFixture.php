<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Support;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutSource;
use ProgrammatorDev\StripeCheckout\Checkout\UiMode;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderLineItemSnapshot;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;

/** Fixed initiating order facts for creation and schema tests; no Kirby app or provider setup. */
final class OrderFixture
{
    /** @param array<array-key, OrderLineItemSnapshot>|null $lineItems */
    public static function context(
        ?array $lineItems = null,
        CheckoutSource $checkoutSource = CheckoutSource::Cart,
        ?string $revision = 'revision',
        ?string $language = 'en',
        ?string $user = null,
    ): OrderCreationContext {
        $lineItems ??= [self::lineItem()];

        return new OrderCreationContext(
            uuid: 'Abc123def456GHI7',
            orderNumber: 'ORD-ABC123DEF456GHI7',
            checkoutSource: $checkoutSource,
            cartRevision: $revision,
            userUuid: $user,
            languageCode: $language,
            uiMode: UiMode::Hosted,
            currency: 'EUR',
            lineItems: $lineItems,
        );
    }

    public static function lineItem(): OrderLineItemSnapshot
    {
        return OrderLineItemSnapshot::fromArray(self::lineItemData());
    }

    /** @return array<string, mixed> */
    public static function lineItemData(): array
    {
        // Keep expected persisted facts independent of both production mappers.
        return [
            'reference' => 'page://shirt',
            'quantity' => 2,
            'variantId' => 'large-variant',
            'name' => 'T-shirt',
            'description' => null,
            'images' => ['https://example.com/shirt.jpg'],
            'sku' => 'SHIRT-L',
            'requiresShipping' => true,
            'options' => [[
                'optionId' => 'size',
                'optionName' => 'Size',
                'valueId' => 'large',
                'valueName' => 'Large',
            ]],
            'metadata' => [],
            'priceSource' => 'kirby',
            'stripePriceId' => null,
            'stripeProductId' => null,
            'taxCode' => null,
            'currency' => 'EUR',
            'price' => '16.00',
            'subtotal' => '32.00',
            'providerAmounts' => [
                'price' => 1600,
                'subtotal' => 3200,
            ],
        ];
    }
}
