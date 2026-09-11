<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Checkout\Internal;

/** @internal Shared URL rules for Checkout destinations and Stripe presentation values. */
final class CheckoutUrlValidator
{
    public const RESULT_QUERY_KEY = '_stripe_checkout_result';

    public static function isDestination(string $value, bool $requiresHttps): bool
    {
        $parts = self::parts($value);

        if ($parts === null) {
            return false;
        }

        return self::isAllowedForHttpsPolicy($parts, $requiresHttps);
    }

    public static function isPersistedDestination(string $value, bool $requiresHttps): bool
    {
        $parts = self::parts($value);

        if ($parts === null || isset($parts['fragment']) || self::isAllowedForHttpsPolicy($parts, $requiresHttps) === false) {
            return false;
        }

        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        // This parameter belongs only to the short-lived browser result flow;
        // accepting it in canonical navigation would make that flow ambiguous.
        return array_key_exists(self::RESULT_QUERY_KEY, $query) === false;
    }

    public static function isHostedPresentation(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $parts = self::parts($value);

        return $parts !== null && strtolower((string) $parts['scheme']) === 'https';
    }

    /** @return array<string, int|string>|null */
    private static function parts(string $value): ?array
    {
        $parts = parse_url($value);

        if (
            $parts === false
            || strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || is_string($parts['scheme'] ?? null) === false
            || in_array(strtolower($parts['scheme']), ['http', 'https'], true) === false
            || is_string($parts['host'] ?? null) === false
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return $parts;
    }

    private static function isLocalHost(string $host): bool
    {
        $host = trim($host, '[]');

        return $host === 'localhost'
            || $host === '::1'
            || str_starts_with($host, '127.')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.ddev.site');
    }

    /** @param array<string, int|string> $parts */
    private static function isAllowedForHttpsPolicy(array $parts, bool $requiresHttps): bool
    {
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        return $requiresHttps === false
            || $scheme === 'https'
            || self::isLocalHost($host);
    }
}
