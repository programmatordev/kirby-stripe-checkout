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

    public static function label(mixed $value): string
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
        if (
            is_string($value) === false
            || $value === ''
            || trim($value) !== $value
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidProductException();
        }

        return $value;
    }
}
