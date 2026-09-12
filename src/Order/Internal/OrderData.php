<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order\Internal;

use DateTimeImmutable;
use DateTimeZone;
use Kirby\Uuid\Uri;
use ProgrammatorDev\StripeCheckout\Order\Exception\OrderDataException;
use ProgrammatorDev\StripeCheckout\Support\TextValidator;
use Throwable;

/** @internal Scalar boundaries shared by order content and immutable hook snapshots. */
final class OrderData
{
    public static function string(mixed $value): string
    {
        if (is_string($value) === false || TextValidator::isUtf8($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : self::string($value);
    }

    /** Preserves meaningful whitespace and empty provider answers; text() does not. */
    public static function singleLine(mixed $value, int $max = 2048): string
    {
        if (
            is_string($value) === false
            || TextValidator::isSingleLine($value) === false
            || mb_strlen($value) > $max
        ) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function nullableSingleLine(mixed $value, int $max = 2048): ?string
    {
        return $value === null ? null : self::singleLine($value, $max);
    }

    public static function integer(mixed $value): int
    {
        if (is_int($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function boolean(mixed $value): bool
    {
        if (is_bool($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public static function map(mixed $value): array
    {
        if (is_array($value) === false) {
            throw new OrderDataException();
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) === false) {
                throw new OrderDataException();
            }

            self::string($key);

            $result[$key] = self::normalize($item);
        }

        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return list<mixed> */
    public static function list(mixed $value): array
    {
        if (is_array($value) === false || array_is_list($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function text(mixed $value, int $max = 2048): string
    {
        if (
            is_string($value) === false || $value === '' || trim($value) !== $value
            || TextValidator::isSingleLine($value) === false || mb_strlen($value) > $max
        ) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function uuid(string $value, string $type = 'page'): string
    {
        self::text($value);

        try {
            // Kirby's URI constructor currently treats parse_url(false) as an
            // array. Reject malformed input first to avoid a PHP deprecation.
            $parts = parse_url($value);

            if ($parts === false) {
                throw new OrderDataException();
            }

            $uri = new Uri($parts);
            $id = $uri->host();

            // Use Kirby's URI parser, without resolving a Page or imposing UUID v4.
            if ($uri->type() === $type && $id && $value === $uri->base()) {
                return $value;
            }
        } catch (Throwable) {
            // Keep parser internals and supplied values out of public-safe errors.
        }

        throw new OrderDataException();
    }

    public static function timestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function date(mixed $value): DateTimeImmutable
    {
        $value = self::text($value, 20);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));

        if ($date === false || self::timestamp($date) !== $value) {
            throw new OrderDataException();
        }

        return $date;
    }

    /**
     * Copies references away, sorts maps, and preserves meaningful list order.
     * The depth limit also rejects recursive PHP arrays without invoking objects.
     */
    public static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new OrderDataException();
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                if (is_string($key) && TextValidator::isUtf8($key) === false) {
                    throw new OrderDataException();
                }

                $result[$key] = self::normalize($item, $depth + 1);
            }

            if (array_is_list($value) === false) {
                ksort($result, SORT_STRING);
            }

            return $result;
        }

        if (is_string($value) && TextValidator::isUtf8($value) === false) {
            throw new OrderDataException();
        }

        if ($value !== null && is_string($value) === false && is_int($value) === false && is_bool($value) === false) {
            throw new OrderDataException();
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        // Validate before encoding so serialization cannot invoke user-supplied
        // objects (for example, JsonSerializable) or accept floating-point amounts.
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $allowedKeys
     */
    public static function validateAllowedKeys(array $value, array $allowedKeys): void
    {
        if (array_diff(array_keys($value), $allowedKeys) !== []) {
            throw new OrderDataException();
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string> $requiredKeys
     */
    public static function validateRequiredKeys(array $value, array $requiredKeys): void
    {
        // Presence is separate from validity: a required snapshot member may be null.
        if (array_diff($requiredKeys, array_keys($value)) !== []) {
            throw new OrderDataException();
        }
    }
}
