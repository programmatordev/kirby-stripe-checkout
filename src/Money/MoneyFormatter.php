<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Money;

use Brick\Money\Currency;
use Brick\Money\Money;
use Kirby\Cms\App;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Translation\LocaleResolver;
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
        $locale = (new LocaleResolver($this->kirby))->resolve($locale);

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
        $locale = (new LocaleResolver($this->kirby))->resolve($locale);

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
}
