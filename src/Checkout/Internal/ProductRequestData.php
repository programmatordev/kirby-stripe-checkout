<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

use ProgrammatorDev\StripeCheckout\Checkout\Exception\CheckoutInputException;
use ProgrammatorDev\StripeCheckout\Checkout\SelectionErrorCode;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Product\ProductRequest;
use stdClass;

/**
 * Defines product request parsing, projection, equality, and checked quantity rules.
 * Complete selections are parsed before project resolution, so malformed later items cannot invoke earlier callbacks.
 *
 * @internal
 */
final class ProductRequestData
{
    /** @return non-empty-list<ProductRequest> */
    public static function parseList(mixed $items): array
    {
        self::assertList($items);

        return array_map(self::parse(...), $items);
    }

    /** @return non-empty-list<ProductRequest> */
    public static function parseHttpList(mixed $items, bool $json): array
    {
        self::assertList($items);

        return array_map(static function (mixed $item) use ($json): ProductRequest {
            if ($json) {
                if ($item instanceof stdClass === false) {
                    throw new CheckoutInputException(SelectionErrorCode::INVALID);
                }

                $item = (array) $item;
            }

            return self::parseHttp($item, json: $json);
        }, $items);
    }

    public static function parseHttp(mixed $input, bool $json): ProductRequest
    {
        if (is_array($input) === false || array_diff(array_keys($input), ['reference', 'quantity', 'options']) !== []) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        // HTTP exposes options; PHP callers and stored requests use selectedOptions.
        if (array_key_exists('options', $input)) {
            if ($json && $input['options'] instanceof stdClass === false) {
                throw new CheckoutInputException(SelectionErrorCode::INVALID);
            }

            $input['selectedOptions'] = $json ? (array) $input['options'] : $input['options'];
            unset($input['options']);
        }

        if ($json === false && array_key_exists('quantity', $input)) {
            $input['quantity'] = self::formQuantity($input['quantity']);
        }

        return self::parseSelection($input);
    }

    public static function formQuantity(mixed $value): int
    {
        if (is_string($value) === false || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        // The syntax check excludes zero and signs; conversion must still reject integer overflow.
        $quantity = filter_var($value, FILTER_VALIDATE_INT);

        if ($quantity === false) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        return $quantity;
    }

    /** @phpstan-assert non-empty-list<mixed> $items */
    private static function assertList(mixed $items): void
    {
        if (is_array($items) === false || array_is_list($items) === false || $items === []) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        // Bound submitted work before resolution, even if duplicate selections would merge.
        if (count($items) > ProductRequestNormalizer::MAX_ENTRIES) {
            throw new CheckoutInputException(SelectionErrorCode::LINE_LIMIT_EXCEEDED);
        }
    }

    public static function parse(mixed $input): ProductRequest
    {
        if (is_array($input) === false || array_diff(array_keys($input), ['reference', 'quantity', 'selectedOptions']) !== []) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        return self::parseSelection($input);
    }

    /** @param array<array-key, mixed> $input Keys were checked against the owning PHP or HTTP vocabulary. */
    private static function parseSelection(array $input): ProductRequest
    {
        if (is_string($input['reference'] ?? null) === false) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        // Only omission selects a default; an explicit null remains invalid.
        $quantity = array_key_exists('quantity', $input) ? $input['quantity'] : 1;

        // Preserve the quantity-specific input error; direct ProductRequest construction enforces its own invariant.
        if (is_int($quantity) === false || $quantity < 1) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        $options = array_key_exists('selectedOptions', $input) ? $input['selectedOptions'] : [];

        if (is_array($options) === false) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID);
        }

        try {
            return new ProductRequest($input['reference'], $quantity, $options);
        } catch (InvalidProductException $error) {
            throw new CheckoutInputException(SelectionErrorCode::INVALID, $error);
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
    public static function sameItem(ProductRequest $left, ProductRequest $right): bool
    {
        return $left->reference() === $right->reference()
            && $left->selectedOptions() === $right->selectedOptions();
    }

    public static function addQuantities(int $left, int $right): int
    {
        // Check before adding: PHP converts overflowing integer sums to floats.
        if ($left < 1 || $right < 1 || $left > PHP_INT_MAX - $right) {
            throw new CheckoutInputException(SelectionErrorCode::QUANTITY_INVALID);
        }

        return $left + $right;
    }
}
