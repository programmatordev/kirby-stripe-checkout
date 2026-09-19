<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use Brick\Money\Money;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Shipping\Exception\InvalidShippingQuoteException;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingErrorCode;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingOption;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuote;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingQuoteStatus;

final class ShippingQuoteTest extends TestCase
{
    public function testCreatesAvailableCountryRequiredAndUnavailableQuotes(): void
    {
        $option = self::option();
        $available = ShippingQuote::available([$option]);
        $countryRequired = ShippingQuote::countryRequired();
        $unavailable = ShippingQuote::unavailable('shipping.carrier_unavailable');

        $this->assertSame(ShippingQuoteStatus::Available, $available->status());
        $this->assertSame([$option], $available->options());
        $this->assertNull($available->reasonCode());
        $this->assertSame(ShippingQuoteStatus::CountryRequired, $countryRequired->status());
        $this->assertSame(ShippingErrorCode::COUNTRY_REQUIRED, $countryRequired->reasonCode());
        $this->assertSame([], $countryRequired->options());
        $this->assertSame(ShippingQuoteStatus::Unavailable, $unavailable->status());
        $this->assertSame('shipping.carrier_unavailable', $unavailable->reasonCode());
    }

    public function testRejectsAnEmptyAvailableQuote(): void
    {
        $this->expectException(InvalidShippingQuoteException::class);

        ShippingQuote::available([]);
    }

    public function testRejectsDuplicateOptionKeysAndMixedCurrencies(): void
    {
        try {
            ShippingQuote::available([
                self::option(),
                self::option(label: 'Express'),
            ]);
            self::fail('A duplicate option key should be rejected.');
        } catch (InvalidShippingQuoteException $error) {
            self::assertSame(ShippingErrorCode::INVALID, $error->errorCode());
        }

        try {
            ShippingQuote::available([
                self::option(),
                self::option(key: 'express', label: 'Express', currency: 'USD'),
            ]);
            self::fail('Mixed quote currencies should be rejected.');
        } catch (InvalidShippingQuoteException $error) {
            self::assertSame(ShippingErrorCode::CURRENCY_MISMATCH, $error->errorCode());
        }
    }

    public function testRejectsUnsafeReasonCodes(): void
    {
        $this->expectException(InvalidShippingQuoteException::class);

        ShippingQuote::unavailable("shipping.failure\ninternal detail");
    }

    private static function option(
        string $key = 'standard',
        string $label = 'Standard',
        string $currency = 'EUR',
    ): ShippingOption {
        return new ShippingOption(
            key: $key,
            label: $label,
            amount: Money::of('4.90', $currency),
        );
    }
}
