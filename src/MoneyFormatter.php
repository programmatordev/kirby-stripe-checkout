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
    public static function fromMinorUnit(int $minorAmount, string $currency, bool $format = false): int|float|string
    {
        // normalize currency
        $currency = strtoupper($currency);
        $money = Money::ofMinor($minorAmount, $currency);
        $fractionDigits = $money->getCurrency()->getDefaultFractionDigits();

        // if currency uses no fraction digits (JPY, for example), return an integer
        // otherwise return a float (EUR, for example)
        $amount = $fractionDigits === 0
            ? $money->getAmount()->toInt()
            : $money->getAmount()->toFloat();

        // format number with fraction digits
        // will return a string
        if ($format === true) {
            $amount = number_format($amount, $fractionDigits);
        }

        return $amount;
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
        $money = Money::of($amount, $currency);

        // format(1000, 'EUR') => 1,000.00
        // format(1000, 'JPY') => 1,000
        return number_format(
            $money->getAmount()->toFloat(),
            $money->getCurrency()->getDefaultFractionDigits()
        );
    }
}
