<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Money;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use Symfony\Component\Intl\Currencies;
use Throwable;

/**
 * Formats exact money values with the active Kirby language context.
 *
 * @internal
 */
final class MoneyFormatter
{
    public function __construct(
        private readonly App $kirby,
    ) {}

    public function format(
        Money|string|int $amount,
        Currency|string|null $currency = null,
        ?string $locale = null,
    ): string {
        $money = $this->money($amount, $currency);
        $locale = $this->locale($locale);

        try {
            return $money->formatToLocale($locale);
        } catch (Throwable $error) {
            throw new MoneyException('money.format_failed', $error);
        }
    }

    public function symbol(
        Currency|string $currency,
        ?string $locale = null,
    ): string {
        $code = $this->currency($currency)->getCurrencyCode();
        $locale = $this->locale($locale);

        try {
            return Currencies::getSymbol($code, $locale);
        } catch (Throwable $error) {
            throw new MoneyException('money.format_failed', $error);
        }
    }

    private function money(
        Money|string|int $amount,
        Currency|string|null $currency,
    ): Money {
        if ($amount instanceof Money) {
            if ($currency !== null) {
                throw new MoneyException('money.currency_redundant');
            }

            return $amount;
        }

        if ($currency === null) {
            throw new MoneyException('money.currency_required');
        }

        if (is_string($amount) && preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $amount) !== 1) {
            throw new MoneyException('money.amount_invalid');
        }

        try {
            return Money::of($amount, $this->currency($currency));
        } catch (Throwable $error) {
            throw new MoneyException('money.amount_invalid', $error);
        }
    }

    private function currency(Currency|string $currency): Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        try {
            return Currency::of($currency);
        } catch (Throwable $error) {
            throw new MoneyException('money.currency_invalid', $error);
        }
    }

    private function locale(?string $explicit): string
    {
        if ($explicit !== null) {
            return $this->normalizeLocale($explicit);
        }

        $languageLocale = $this->kirby->language()?->locale(LC_MONETARY);

        if (is_string($languageLocale) && trim($languageLocale) !== '') {
            return $this->normalizeLocale($languageLocale);
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
            return $this->normalizeLocale($configured);
        }

        return 'en_US';
    }

    private function normalizeLocale(string $locale): string
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
