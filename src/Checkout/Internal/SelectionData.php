<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;

/**
 * Defines selection parsing, projection, equality, and checked quantity rules.
 *
 * @internal
 */
final class SelectionData
{
    public static function parse(mixed $input): ProductRequest
    {
        if (
            is_array($input) === false
            || array_diff(array_keys($input), ['reference', 'quantity', 'selectedOptions']) !== []
            || is_string($input['reference'] ?? null) === false
        ) {
            throw new CheckoutInputException('selection.invalid');
        }

        // Only omission selects a default; an explicit null remains invalid.
        $quantity = array_key_exists('quantity', $input) ? $input['quantity'] : 1;

        if (is_int($quantity) === false || $quantity < 1) {
            throw new CheckoutInputException('selection.quantity_invalid');
        }

        $options = array_key_exists('selectedOptions', $input) ? $input['selectedOptions'] : [];

        if (is_array($options) === false) {
            throw new CheckoutInputException('selection.invalid');
        }

        try {
            return new ProductRequest($input['reference'], $quantity, $options);
        } catch (InvalidProductException $error) {
            throw new CheckoutInputException('selection.invalid', $error);
        }
    }

    /** @return array{reference: string, quantity: int, selectedOptions: array<string, string>} */
    public static function toArray(ProductRequest $request): array
    {
        return [
            'reference' => $request->reference(),
            'quantity' => $request->quantity(),
            'selectedOptions' => $request->selectedOptions(),
        ];
    }

    /** Quantity is deliberately excluded: matching selections share one cart line. */
    public static function equivalent(ProductRequest $left, ProductRequest $right): bool
    {
        return $left->reference() === $right->reference()
            && $left->selectedOptions() === $right->selectedOptions();
    }

    public static function addQuantities(int $left, int $right): int
    {
        // Check before adding: PHP converts overflowing integer sums to floats.
        if ($left < 1 || $right < 1 || $left > PHP_INT_MAX - $right) {
            throw new CheckoutInputException('selection.quantity_invalid');
        }

        return $left + $right;
    }
}
