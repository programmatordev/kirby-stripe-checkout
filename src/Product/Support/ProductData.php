<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Product\Support;

use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;

/**
 * Centralizes the bounded scalar rules shared by public product values.
 *
 * @internal
 */
final class ProductData
{
    public static function identifier(mixed $value): string
    {
        return self::requiredString($value, 128);
    }

    public static function name(mixed $value): string
    {
        return self::requiredString($value, 500);
    }

    public static function reference(mixed $value): string
    {
        return self::requiredString($value, 2048);
    }

    public static function optionalString(mixed $value, int $maximum = 5000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::requiredString($value, $maximum);
    }

    public static function requiredString(mixed $value, int $maximum): string
    {
        // Reject unsafe Unicode at resolution, not only when the product is later
        // frozen into an order. Keep the existing byte-based product limits.
        if (
            is_string($value) === false
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
            || mb_check_encoding($value, 'UTF-8') === false
            || preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) !== 0
        ) {
            throw new InvalidProductException();
        }

        return $value;
    }
}
