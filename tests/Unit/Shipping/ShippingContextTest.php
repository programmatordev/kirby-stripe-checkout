<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class ShippingContextTest extends TestCase
{
    public function testExposesShippingCountryAndTaxDefaults(): void
    {
        $context = new ShippingContext(
            shippingCountry: 'PT',
            taxBehavior: TaxBehavior::Inclusive,
            taxCode: 'txcd_92010001',
        );

        $this->assertSame('PT', $context->shippingCountry());
        $this->assertSame(TaxBehavior::Inclusive, $context->taxBehavior());
        $this->assertSame('txcd_92010001', $context->taxCode());
    }

    public function testRejectsAnUnsupportedShippingCountry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            shippingCountry: 'CU',
        );
    }

    public function testRejectsAnInvalidShippingTaxCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            shippingCountry: 'PT',
            taxCode: 'shipping',
        );
    }
}
