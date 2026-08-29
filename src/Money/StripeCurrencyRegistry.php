<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Money;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\IntegerOverflowException;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Money;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use Throwable;

/**
 * Owns Stripe's presentment-currency units where they differ from ISO data.
 *
 * @internal
 */
final class StripeCurrencyRegistry
{
    /** @var list<string> */
    private const SUPPORTED = [
        'AED', 'AFN', 'ALL', 'AMD', 'ANG', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN',
        'BAM', 'BBD', 'BDT', 'BGN', 'BHD', 'BIF', 'BMD', 'BND', 'BOB', 'BRL',
        'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP',
        'CRC', 'CVE', 'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ETB', 'EUR',
        'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GNF', 'GTQ', 'GYD', 'HKD',
        'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JMD', 'JOD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP',
        'LKR', 'LRD', 'LSL', 'MAD', 'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP',
        'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO', 'NOK',
        'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG',
        'QAR', 'RON', 'RSD', 'RUB', 'RWF', 'SAR', 'SBD', 'SCR', 'SEK', 'SGD',
        'SHP', 'SLE', 'SOS', 'SRD', 'STD', 'SZL', 'THB', 'TJS', 'TND', 'TOP',
        'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VND',
        'VUV', 'WST', 'XAF', 'XCD', 'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW',
    ];

    /** @var list<string> */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** @var list<string> */
    private const WHOLE_UNIT_TWO_DECIMAL = ['ISK', 'UGX'];

    /** @return list<string> */
    public function codes(): array
    {
        return self::SUPPORTED;
    }

    public function supports(string $currency): bool
    {
        return in_array($currency, self::SUPPORTED, true);
    }

    public function exponent(string $currency): int
    {
        $this->assertSupported($currency);

        if (
            in_array($currency, self::ZERO_DECIMAL, true)
            && in_array($currency, self::WHOLE_UNIT_TWO_DECIMAL, true) === false
        ) {
            return 0;
        }

        // Stripe treats all other presentment currencies as two-decimal,
        // including ISO three-decimal currencies such as BHD and KWD.
        return 2;
    }

    public function fromDecimal(string $amount, string $currency): MoneySnapshot
    {
        $this->assertSupported($currency);

        if (preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $amount) !== 1) {
            throw new MoneyException('money.amount_invalid');
        }

        try {
            $decimal = BigDecimal::of($amount);

            if (in_array($currency, self::WHOLE_UNIT_TWO_DECIMAL, true)) {
                $decimal->toBigInteger();
            }

            $minorAmount = $decimal
                ->withPointMovedRight($this->exponent($currency))
                ->toBigInteger()
                ->toInt();
        } catch (RoundingNecessaryException $error) {
            throw new MoneyException('money.amount_inexact', $error);
        } catch (IntegerOverflowException $error) {
            throw new MoneyException('money.amount_overflow', $error);
        } catch (MathException $error) {
            throw new MoneyException('money.amount_invalid', $error);
        }

        return new MoneySnapshot($currency, $minorAmount);
    }

    public function fromMoney(Money $money): MoneySnapshot
    {
        if ($money->isNegative()) {
            throw new MoneyException('money.amount_negative');
        }

        return $this->fromDecimal(
            $money->getAmount()->toString(),
            $money->getCurrency()->getCurrencyCode(),
        );
    }

    public function fromProviderAmount(int $minorAmount, string $currency): MoneySnapshot
    {
        $this->assertSupported($currency);

        if (
            in_array($currency, self::WHOLE_UNIT_TWO_DECIMAL, true)
            && $minorAmount % 100 !== 0
        ) {
            throw new MoneyException('money.provider_amount_invalid');
        }

        return new MoneySnapshot($currency, $minorAmount);
    }

    public function toMoney(MoneySnapshot $snapshot): Money
    {
        $this->assertSupported($snapshot->currency());

        if (
            in_array($snapshot->currency(), self::WHOLE_UNIT_TWO_DECIMAL, true)
            && $snapshot->minorAmount() % 100 !== 0
        ) {
            throw new MoneyException('money.provider_amount_invalid');
        }

        try {
            $amount = BigDecimal::of($snapshot->minorAmount())
                ->withPointMovedLeft($this->exponent($snapshot->currency()));

            return Money::of($amount, $snapshot->currency());
        } catch (Throwable $error) {
            throw new MoneyException('money.provider_amount_invalid', $error);
        }
    }

    private function assertSupported(string $currency): void
    {
        if ($this->supports($currency) === false) {
            throw new MoneyException('money.currency_unsupported');
        }
    }
}
