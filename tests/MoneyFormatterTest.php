<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use ProgrammatorDev\StripeCheckout\MoneyFormatter;

class MoneyFormatterTest extends BaseTestCase
{
    public function testToMinorUnit(): void
    {
        $this->assertSame(100000, MoneyFormatter::toMinorUnit(1000, 'EUR'));
        $this->assertSame(1000, MoneyFormatter::toMinorUnit(1000, 'JPY'));
    }

    public function testFromMinorUnit(): void
    {
        $this->assertSame(1000.0, MoneyFormatter::fromMinorUnit(100000, 'EUR'));
        $this->assertSame(1000, MoneyFormatter::fromMinorUnit(1000, 'JPY'));
    }

    public function testFormat(): void
    {
        $this->assertSame('€ 1,000.00', MoneyFormatter::format(1000, 'EUR'));
        $this->assertSame('¥ 1,000', MoneyFormatter::format(1000, 'JPY'));
    }

    public function testFormatFromMinorUnit(): void
    {
        $this->assertSame('€ 1,000.00', MoneyFormatter::formatFromMinorUnit(100000, 'EUR'));
        $this->assertSame('¥ 1,000', MoneyFormatter::formatFromMinorUnit(1000, 'JPY'));
    }
}
