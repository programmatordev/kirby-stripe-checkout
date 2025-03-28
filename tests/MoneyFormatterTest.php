<?php

namespace ProgrammatorDev\StripeCheckout\Test;

use ProgrammatorDev\StripeCheckout\MoneyFormatter;

class MoneyFormatterTest extends AbstractTestCase
{
    public function testToMinorUnit(): void
    {
        $this->assertSame(100000, MoneyFormatter::toMinorUnit(1000, 'EUR'));
        $this->assertSame(1000, MoneyFormatter::toMinorUnit(1000, 'JPY'));
    }

    public function testFromMinorUnit(): void
    {
        $this->assertSame(1000.0, MoneyFormatter::fromMinorUnit(100000, 'EUR'));
        $this->assertSame('1,000.00', MoneyFormatter::fromMinorUnit(100000, 'EUR', true));
        $this->assertSame(1000, MoneyFormatter::fromMinorUnit(1000, 'JPY'));
        $this->assertSame('1,000', MoneyFormatter::fromMinorUnit(1000, 'JPY', true));
    }

    public function testFormat(): void
    {
        $this->assertSame('1,000.00', MoneyFormatter::format(1000, 'EUR'));
        $this->assertSame('1,000', MoneyFormatter::format(1000, 'JPY'));
    }
}
