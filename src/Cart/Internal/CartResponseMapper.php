<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Cart\Internal;

use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Cart\Cart;
use ProgrammatorDev\StripeCheckout\Cart\CartError;

/** @internal Explicit public JSON allowlist; never serialize domain objects wholesale. */
final class CartResponseMapper
{
    /** @return array<string, mixed> */
    public static function cart(Cart $cart): array
    {
        $items = [];

        foreach ($cart->items() as $item) {
            $product = $item->product();
            $options = [];

            foreach ($item->options() as $option) {
                $options[] = [
                    'optionId' => $option->optionId(),
                    'optionName' => $option->optionName(),
                    'valueId' => $option->valueId(),
                    'valueName' => $option->valueName(),
                ];
            }

            $request = [
                'reference' => $item->request()->reference(),
                'quantity' => $item->quantity(),
                // Empty option maps remain JSON objects, not arrays.
                'options' => (object) $item->request()->selectedOptions(),
            ];
            $items[] = [
                'id' => $item->id(),
                'request' => $request,
                'product' => $product === null ? null : [
                    'name' => $product->name(),
                    'description' => $product->description(),
                    'images' => $product->imageUrls(),
                    'sku' => $product->sku(),
                    'requiresShipping' => $product->requiresShipping(),
                    'options' => $options,
                ],
                'price' => self::money($item->price()),
                'subtotal' => self::money($item->subtotal()),
                'hasErrors' => $item->hasErrors(),
                'errors' => array_map(self::error(...), $item->errors()),
            ];
        }

        return [
            'revision' => $cart->revision(),
            'items' => $items,
            'count' => $cart->count(),
            'totalQuantity' => $cart->totalQuantity(),
            'currency' => $cart->currency()?->getCurrencyCode(),
            'subtotal' => self::money($cart->subtotal()),
            'destinationCountry' => $cart->destinationCountry(),
            'empty' => $cart->isEmpty(),
            'hasErrors' => $cart->hasErrors(),
            'errors' => array_map(self::error(...), $cart->errors()),
        ];
    }

    /** @return array<string, mixed> */
    public static function error(CartError $error): array
    {
        return array_filter([
            'code' => $error->code(),
            'message' => $error->message(),
            'itemId' => $error->itemId(),
            'field' => $error->field(),
            'details' => $error->context() === [] ? null : $error->context(),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** @return array{amount: string, currency: string}|null */
    private static function money(?Money $money): ?array
    {
        // Decimal strings preserve exact amounts and trailing currency decimals
        // without forcing clients to parse them as floating-point numbers.
        return $money === null ? null : [
            'amount' => (string) $money->getAmount(),
            'currency' => $money->getCurrency()->getCurrencyCode(),
        ];
    }
}
