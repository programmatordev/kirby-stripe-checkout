<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Money;

use Brick\Money\Currency;
use Brick\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Exception\MoneyException;
use ProgrammatorDev\StripeCheckout\Money\StripeCurrencyRegistry;
use Symfony\Component\Intl\Currencies;

final class StripeCurrencyRegistryTest extends TestCase
{
    private StripeCurrencyRegistry $currencies;

    protected function setUp(): void
    {
        $this->currencies = new StripeCurrencyRegistry();
    }

    /** @return iterable<string, array{string, string, int, string}> */
    public static function exactAmountProvider(): iterable
    {
        yield 'two-decimal EUR' => ['19.95', 'EUR', 1995, '19.95'];
        yield 'zero-decimal JPY' => ['500', 'JPY', 500, '500'];
        yield 'zero-decimal MGA with two ISO digits' => ['5', 'MGA', 5, '5.00'];
        yield 'three-decimal BHD uses Stripe two-decimal units' => ['1.23', 'BHD', 123, '1.230'];
        yield 'ISK requires whole units represented with two digits' => ['5', 'ISK', 500, '5'];
        yield 'UGX requires whole units represented with two digits' => ['5', 'UGX', 500, '5'];
        yield 'zero is valid' => ['0', 'USD', 0, '0.00'];
        yield 'extra zeroes do not require rounding' => ['19.9500', 'EUR', 1995, '19.95'];
    }

    #[DataProvider('exactAmountProvider')]
    public function testConvertsExactDecimalsToProviderIntegersAndBack(
        string $decimal,
        string $currency,
        int $minorAmount,
        string $brickAmount,
    ): void {
        $snapshot = $this->currencies->fromDecimal($decimal, $currency);
        $money = $this->currencies->toMoney($snapshot);

        $this->assertSame($currency, $snapshot->currency());
        $this->assertSame($minorAmount, $snapshot->minorAmount());
        $this->assertSame($currency, $money->getCurrency()->getCurrencyCode());
        $this->assertSame($brickAmount, $money->getAmount()->toString());
    }

    public function testConvertsBrickMoneyWithoutUsingItsIsoMinorAmount(): void
    {
        $snapshot = $this->currencies->fromMoney(Money::of('1.230', 'BHD'));

        $this->assertSame(123, $snapshot->minorAmount());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidDecimalProvider(): iterable
    {
        yield 'negative merchant amount' => ['-1', 'EUR', 'money.amount_invalid'];
        yield 'comma decimal' => ['1,20', 'EUR', 'money.amount_invalid'];
        yield 'currency symbol' => ['€1.20', 'EUR', 'money.amount_invalid'];
        yield 'exponent' => ['1e2', 'EUR', 'money.amount_invalid'];
        yield 'surrounding whitespace' => [' 1.20 ', 'EUR', 'money.amount_invalid'];
        yield 'fractional JPY' => ['1.5', 'JPY', 'money.amount_inexact'];
        yield 'fractional ISK' => ['1.01', 'ISK', 'money.amount_inexact'];
        yield 'third EUR decimal' => ['1.001', 'EUR', 'money.amount_inexact'];
        yield 'third BHD decimal' => ['1.234', 'BHD', 'money.amount_inexact'];
        yield 'lowercase currency' => ['1', 'eur', 'money.currency_unsupported'];
        yield 'unsupported currency' => ['1', 'XXX', 'money.currency_unsupported'];
    }

    #[DataProvider('invalidDecimalProvider')]
    public function testRejectsInvalidOrInexactMerchantAmounts(
        string $decimal,
        string $currency,
        string $errorCode,
    ): void {
        try {
            $this->currencies->fromDecimal($decimal, $currency);
            $this->fail('Expected the money input to be rejected.');
        } catch (MoneyException $error) {
            $this->assertSame($errorCode, $error->errorCode());
            $this->assertStringNotContainsString($decimal, $error->getMessage());
        }
    }

    public function testRejectsNegativeUnitMoney(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('money.amount_negative');

        $this->currencies->fromMoney(Money::of('-1', 'EUR'));
    }

    public function testRejectsNativeIntegerOverflow(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('money.amount_overflow');

        $this->currencies->fromDecimal((string) PHP_INT_MAX . '0', 'USD');
    }

    public function testRejectsImpossibleSpecialProviderAmounts(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessage('money.provider_amount_invalid');

        $this->currencies->fromProviderAmount(501, 'ISK');
    }

    public function testProviderSnapshotsCanRepresentSignedReconciliationAmounts(): void
    {
        $snapshot = $this->currencies->fromProviderAmount(-1995, 'EUR');
        $money = $this->currencies->toMoney($snapshot);

        $this->assertSame('-19.95', $money->getAmount()->toString());
    }

    public function testEverySupportedCodeHasCurrencyMetadata(): void
    {
        $codes = $this->currencies->codes();

        $this->assertSame($codes, array_values(array_unique($codes)));

        foreach ($codes as $code) {
            $this->assertSame($code, Currency::of($code)->getCurrencyCode());
            $this->assertTrue(Currencies::exists($code), $code . ' is missing from Symfony Intl.');
        }
    }
}
