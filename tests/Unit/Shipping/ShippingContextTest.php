<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class ShippingContextTest extends TestCase
{
    public function testExposesDestinationAndTaxDefaults(): void
    {
        $context = new ShippingContext(
            destinationCountry: 'PT',
            taxBehavior: TaxBehavior::Inclusive,
            taxCode: 'txcd_92010001',
        );

        $this->assertSame('PT', $context->destinationCountry());
        $this->assertSame(TaxBehavior::Inclusive, $context->taxBehavior());
        $this->assertSame('txcd_92010001', $context->taxCode());
    }

    public function testRejectsAnUnsupportedDestinationCountry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            destinationCountry: 'CU',
        );
    }

    public function testRejectsAnInvalidShippingTaxCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            destinationCountry: 'PT',
            taxCode: 'shipping',
        );
    }
}
