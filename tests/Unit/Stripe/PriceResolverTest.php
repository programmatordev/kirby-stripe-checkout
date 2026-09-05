<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Test\Unit\Stripe;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProgrammatorDev\StripeCheckout\Product\Exception\InvalidProductException;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceRecord;
use ProgrammatorDev\StripeCheckout\Stripe\Price\PriceResolver;
use ProgrammatorDev\StripeCheckout\Test\Support\Stripe\FakePriceProvider;

final class PriceResolverTest extends TestCase
{
    public function testFreshResolutionReturnsAuthoritativePriceAndProductFacts(): void
    {
        $record = self::record();
        $provider = new FakePriceProvider(prices: [$record->priceId => $record]);
        $resolved = (new PriceResolver($provider))->resolve($record->priceId, 'EUR');

        $this->assertSame(['price_standard'], $provider->retrievedIds);
        $this->assertSame('price_standard', $resolved->priceId());
        $this->assertSame('prod_standard', $resolved->productId());
        $this->assertSame('Canvas bag', $resolved->name());
        $this->assertSame('16.00', $resolved->price()->getAmount()->toString());
        $this->assertSame('EUR', $resolved->price()->getCurrency()->getCurrencyCode());
        $this->assertSame('exclusive', $resolved->taxBehavior());
        $this->assertSame('txcd_99999999', $resolved->taxCode());
    }

    public function testMathematicallyIntegralDecimalProviderAmountIsAccepted(): void
    {
        $record = self::record(unitAmount: null, unitAmountDecimal: '1600.000');
        $resolved = (new PriceResolver(new FakePriceProvider()))->resolveRecord($record, 'EUR');

        $this->assertSame('16.00', $resolved->price()->getAmount()->toString());
    }

    #[DataProvider('ineligibleRecords')]
    public function testUnsupportedPriceAndProductShapesAreRejected(PriceRecord $record): void
    {
        $this->expectException(InvalidProductException::class);

        (new PriceResolver(new FakePriceProvider()))->resolveRecord(
            $record,
            'EUR',
        );
    }

    /** @return iterable<string, array{PriceRecord}> */
    public static function ineligibleRecords(): iterable
    {
        yield 'inactive price' => [self::record(active: false)];
        yield 'invalid price id' => [self::record(priceId: 'invalid')];
        yield 'recurring price' => [self::record(type: 'recurring', hasRecurring: true)];
        yield 'tiered price' => [self::record(billingScheme: 'tiered', hasTiers: true)];
        yield 'tiers mode' => [self::record(tiersMode: 'volume')];
        yield 'custom amount' => [self::record(hasCustomUnitAmount: true)];
        yield 'quantity transform' => [self::record(hasQuantityTransform: true)];
        yield 'missing amount' => [self::record(unitAmount: null, unitAmountDecimal: null)];
        yield 'negative amount' => [self::record(unitAmount: -1, unitAmountDecimal: null)];
        yield 'fractional provider unit' => [self::record(unitAmount: null, unitAmountDecimal: '1600.5')];
        yield 'conflicting amount fields' => [self::record(unitAmount: 1600, unitAmountDecimal: '1700')];
        yield 'inactive product' => [self::record(productActive: false)];
        yield 'invalid product id' => [self::record(productId: 'invalid')];
        yield 'missing product name' => [self::record(productName: null)];
        yield 'invalid product name' => [self::record(productName: ' Canvas bag')];
        yield 'invalid product image' => [self::record(productImages: ['ftp://example.com/bag.jpg'])];
        yield 'invalid tax behavior' => [self::record(taxBehavior: 'automatic')];
        yield 'currency mismatch' => [self::record(currency: 'usd')];
    }

    /** @param list<string> $productImages */
    private static function record(
        string $priceId = 'price_standard',
        bool $active = true,
        string $billingScheme = 'per_unit',
        string $currency = 'eur',
        bool $hasCustomUnitAmount = false,
        ?string $nickname = 'Standard',
        bool $hasRecurring = false,
        ?string $taxBehavior = 'exclusive',
        bool $hasTiers = false,
        ?string $tiersMode = null,
        bool $hasQuantityTransform = false,
        string $type = 'one_time',
        ?int $unitAmount = 1600,
        ?string $unitAmountDecimal = '1600',
        ?string $productId = 'prod_standard',
        bool $productActive = true,
        ?string $productName = 'Canvas bag',
        ?string $productDescription = 'Heavy canvas.',
        array $productImages = ['https://example.com/bag.jpg'],
        ?string $productTaxCode = 'txcd_99999999',
    ): PriceRecord {
        return new PriceRecord(
            $priceId,
            $active,
            $billingScheme,
            $currency,
            $hasCustomUnitAmount,
            $nickname,
            $hasRecurring,
            $taxBehavior,
            $hasTiers,
            $tiersMode,
            $hasQuantityTransform,
            $type,
            $unitAmount,
            $unitAmountDecimal,
            $productId,
            $productActive,
            $productName,
            $productDescription,
            $productImages,
            $productTaxCode,
        );
    }
}
