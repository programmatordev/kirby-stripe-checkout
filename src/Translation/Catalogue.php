<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Translation;

/**
 * Loads the bundled catalogues and exposes their validated public key set.
 *
 * @internal
 */
final class Catalogue
{
    public const PREFIX = 'programmatordev.stripe-checkout.';

    /** @return array<string, array<string, string>> */
    public static function bundled(): array
    {
        return [
            'en' => self::load('en'),
            'pt_PT' => self::load('pt_PT'),
        ];
    }

    /** @return list<string> */
    public static function suffixes(): array
    {
        $suffixes = [];

        foreach (array_keys(self::load('en')) as $key) {
            $suffixes[] = substr($key, strlen(self::PREFIX));
        }

        sort($suffixes);

        return $suffixes;
    }

    /** @return array<string, string> */
    private static function load(string $locale): array
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require dirname(__DIR__, 2) . '/translations/' . $locale . '.php';

        return $catalogue;
    }
}
