<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Support;

/** @internal Shared lexical checks for strings crossing domain boundaries. */
final class TextValidator
{
    public static function isUtf8(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8');
    }

    /** Rejects controls and Unicode line/paragraph separators at single-line boundaries. */
    public static function isSingleLine(string $value): bool
    {
        return self::isUtf8($value)
            && preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $value) === 0;
    }
}
