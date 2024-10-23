<?php

namespace ProgrammatorDev\StripeCheckout;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Symfony\Component\Intl\Currencies;

class MoneyFormatter
{
    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    public static function toMinorUnit(int|float $amount, string $currency): int
    {
        // normalize currency
        $currency = strtoupper($currency);
        return Money::of($amount, $currency)->getMinorAmount()->toInt();
    }

    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    public static function fromMinorUnit(int $minorAmount, string $currency): int|float
    {
        // normalize currency
        $currency = strtoupper($currency);
        $money = Money::ofMinor($minorAmount, $currency);

        // if currency uses no fraction digits (JPY, for example), return an integer
        // otherwise return a float (EUR, for example)
        return $money->getCurrency()->getDefaultFractionDigits() === 0
            ? $money->getAmount()->toInt()
            : $money->getAmount()->toFloat();
    }

    /**
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     */
    public static function format(int|float $amount, string $currency): string
    {
        // normalize currency
        $currency = strtoupper($currency);
        $symbol = Currencies::getSymbol($currency);
        $money = Money::of($amount, $currency);

        $amountFormatted = number_format(
            $money->getAmount()->toFloat(),
            $money->getCurrency()->getDefaultFractionDigits()
        );

        // format(1000, 'EUR') => € 1,000.00
        // format(1000, 'JPY') => ¥ 1,000
        return sprintf('%s %s', $symbol, $amountFormatted);
    }

    /**
     * @throws RoundingNecessaryException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     */
    public static function formatFromMinorUnit(int $minorAmount, string $currency): string
    {
        $amount = self::fromMinorUnit($minorAmount, $currency);
        return self::format($amount, $currency);
    }
}
