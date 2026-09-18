<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Shipping;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Shipping\ShippingContext;
use ProgrammatorDev\StripeCheckout\Tax\TaxBehavior;

final class ShippingContextTest extends TestCase
{
    public function testExposesDestinationPolicyAndTaxDefaults(): void
    {
        $context = new ShippingContext(
            allowedCountries: ['PT', 'ES'],
            destinationCountry: 'PT',
            taxBehavior: TaxBehavior::Inclusive,
            taxCode: 'txcd_92010001',
        );

        $this->assertSame(['PT', 'ES'], $context->allowedCountries());
        $this->assertSame('PT', $context->destinationCountry());
        $this->assertSame(TaxBehavior::Inclusive, $context->taxBehavior());
        $this->assertSame('txcd_92010001', $context->taxCode());
    }

    public function testRejectsADisallowedDestination(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            allowedCountries: ['PT', 'ES'],
            destinationCountry: 'FR',
        );
    }

    public function testRejectsAnInvalidShippingTaxCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ShippingContext(
            allowedCountries: ['PT'],
            destinationCountry: 'PT',
            taxCode: 'shipping',
        );
    }
}
