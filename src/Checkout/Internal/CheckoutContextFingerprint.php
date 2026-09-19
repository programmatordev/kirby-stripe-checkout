<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\CheckoutContext;
use ProgrammatorDev\StripeCheckout\Checkout\CheckoutLineItem;
use ProgrammatorDev\StripeCheckout\Order\Internal\OrderData;
use ProgrammatorDev\StripeCheckout\Order\OrderCreationContext;
use ProgrammatorDev\StripeCheckout\Product\SelectedOption;

/**
 * @internal Canonical purchase fingerprint shared by quote and order preparation.
 *
 * Only facts exposed to shipping resolution are included. Provider enrichment
 * added later to the Order snapshot cannot have influenced the accepted quote.
 */
final class CheckoutContextFingerprint
{
    public static function fromCheckout(CheckoutContext $context): string
    {
        $items = array_map(
            static fn(CheckoutLineItem $item): array => [
                'reference' => $item->productReference(),
                'variantId' => $item->variantId(),
                'sku' => $item->sku(),
                'quantity' => $item->quantity(),
                'price' => (string) $item->price()->getAmount(),
                'subtotal' => (string) $item->subtotal()->getAmount(),
                'requiresShipping' => $item->requiresShipping(),
                'options' => array_map(
                    static fn(SelectedOption $option): array => [
                        'optionId' => $option->optionId(),
                        'optionName' => $option->optionName(),
                        'valueId' => $option->valueId(),
                        'valueName' => $option->valueName(),
                    ],
                    $item->options(),
                ),
                'metadata' => $item->metadata(),
            ],
            $context->items(),
        );

        return self::fingerprint(
            items: $items,
            languageCode: $context->languageCode(),
            userUuid: $context->userUuid(),
            checkoutSource: $context->checkoutSource()->value,
            uiMode: $context->uiMode()->value,
            currency: $context->currency()->getCurrencyCode(),
            subtotal: (string) $context->subtotal()->getAmount(),
        );
    }

    public static function fromOrder(OrderCreationContext $context): string
    {
        $items = array_map(
            static fn(array $item): array => [
                'reference' => $item['reference'],
                'variantId' => $item['variantId'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'subtotal' => $item['subtotal'],
                'requiresShipping' => $item['requiresShipping'],
                'options' => $item['options'],
                'metadata' => $item['metadata'],
            ],
            $context->lineItems(),
        );

        return self::fingerprint(
            items: $items,
            languageCode: $context->languageCode(),
            userUuid: $context->userUuid(),
            checkoutSource: $context->checkoutSource()->value,
            uiMode: $context->uiMode()->value,
            currency: $context->currency(),
            subtotal: (string) $context->subtotal()->getAmount(),
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private static function fingerprint(
        array $items,
        ?string $languageCode,
        ?string $userUuid,
        string $checkoutSource,
        string $uiMode,
        string $currency,
        string $subtotal,
    ): string {
        return hash('sha256', OrderData::json([
            'items' => $items,
            'languageCode' => $languageCode,
            'userUuid' => $userUuid,
            'checkoutSource' => $checkoutSource,
            'uiMode' => $uiMode,
            'currency' => $currency,
            'subtotal' => $subtotal,
        ]));
    }
}
