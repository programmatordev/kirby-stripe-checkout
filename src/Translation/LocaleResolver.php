<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Translation;

use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;

/**
 * Resolves one validated locale from explicit, language, or App settings.
 *
 * @internal
 */
final class LocaleResolver
{
    public function __construct(private readonly App $kirby) {}

    public function resolve(?string $explicit = null): string
    {
        if ($explicit !== null) {
            return $this->normalize($explicit);
        }

        $languageLocale = $this->kirby->language()?->locale(LC_MONETARY);

        if (is_string($languageLocale) && trim($languageLocale) !== '') {
            return $this->normalize($languageLocale);
        }

        $configured = $this->kirby->option('locale');

        if (is_array($configured)) {
            $configured = $configured[LC_MONETARY]
                ?? $configured['LC_MONETARY']
                ?? $configured[LC_ALL]
                ?? $configured['LC_ALL']
                ?? null;
        }

        if (is_string($configured) && trim($configured) !== '') {
            return $this->normalize($configured);
        }

        return 'en_US';
    }

    private function normalize(string $locale): string
    {
        if ($locale === '' || trim($locale) !== $locale) {
            throw new MoneyException('money.locale_invalid');
        }

        $locale = preg_replace('/[.@].*$/', '', str_replace('-', '_', $locale));
        $locale = is_string($locale) ? \Locale::canonicalize($locale) : null;

        if (
            is_string($locale) === false
            || preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8})*$/D', $locale) !== 1
        ) {
            throw new MoneyException('money.locale_invalid');
        }

        return $locale;
    }
}
